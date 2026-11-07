<?php

namespace Tests\Feature\Messages;

use App\Events\MessagesChangent;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MessageClientele;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les coches des messages façon WhatsApp (décision de Jim, 08.11.2026) :
 * une coche, envoyé ; deux grises, distribué (l'app du destinataire l'a
 * reçu) ; deux bleues, lu. L'expéditeur est prévenu en temps réel de
 * chaque changement, une fois, sans boucle.
 */
class MessageReceiptsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $lausanne;

    private string $julieToken;

    private User $owner;

    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lausanne = Clinique::factory()->create(['nom' => 'Deux Rives Lausanne', 'ville' => 'Lausanne']);
        ['jeton' => $this->julieToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Julie Muller'], 'asv');
        ['user' => $this->owner, 'jeton' => $this->ownerToken] = $this->proprietaireAvecJeton();
        Client::factory()->create(['user_id' => $this->owner->id, 'organisation_id' => $this->lausanne->societe->organisation_id, 'prenom' => 'Léa', 'nom' => 'Martin']);
    }

    /** @return list<string> les canaux prévenus depuis le dernier appel */
    private function channels(): array
    {
        $channels = Event::dispatched(MessagesChangent::class)->map(fn (array $e): string => $e[0]->canal)->values()->all();
        Event::fake([MessagesChangent::class]);

        return $channels;
    }

    private function ownerThread(): array
    {
        return $this->getJson('/api/v1/espace/messages?clinique_id='.$this->lausanne->id, $this->enTetes($this->ownerToken))->assertOk()->json('data.messages');
    }

    public function test_a_clinic_message_is_sent_then_delivered_when_the_owner_app_opens_then_read(): void
    {
        $cle = 'u'.$this->owner->id;
        $this->postJson("/api/v1/messages/{$cle}", ['texte' => 'Passez cet après-midi.'], $this->enTetes($this->julieToken))
            ->assertCreated()->assertJsonPath('data.status', 'sent');
        $this->assertSame('sent', $this->getJson('/api/v1/messages', $this->enTetes($this->julieToken))->json('data.0.dernier.status'));
        Event::fake([MessagesChangent::class]);

        // L'app du propriétaire s'ouvre sur son accueil : distribué, la clinique le sait
        $this->getJson('/api/v1/espace/accueil', $this->enTetes($this->ownerToken))->assertOk();
        $this->assertNotNull(MessageClientele::query()->sole()->delivered_at);
        $this->assertNull(MessageClientele::query()->sole()->lu_le);
        $this->assertSame(['clinique.'.$this->lausanne->id], $this->channels());
        $this->getJson("/api/v1/messages/{$cle}", $this->enTetes($this->julieToken))->assertJsonPath('data.messages.0.status', 'delivered');
        $this->assertSame('delivered', $this->getJson('/api/v1/messages', $this->enTetes($this->julieToken))->json('data.0.dernier.status'));

        // Il ouvre le fil : lu, la clinique le sait ; relire ne prévient plus personne
        $this->channels();
        $this->ownerThread();
        $this->assertSame(['clinique.'.$this->lausanne->id], $this->channels());
        $this->getJson("/api/v1/messages/{$cle}", $this->enTetes($this->julieToken))->assertJsonPath('data.messages.0.status', 'read');
        $this->channels();
        $this->ownerThread();
        $this->getJson('/api/v1/espace/accueil', $this->enTetes($this->ownerToken))->assertOk();
        $this->assertSame([], $this->channels());
    }

    public function test_an_owner_message_is_delivered_when_the_clinic_list_is_read_then_read_when_opened(): void
    {
        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $this->lausanne->id, 'texte' => 'Félix tousse.'], $this->enTetes($this->ownerToken))
            ->assertCreated()->assertJsonPath('data.status', 'sent');
        Event::fake([MessagesChangent::class]);

        // L'app de la clinique relit sa liste (Reverb l'a prévenue) : distribué, le propriétaire le sait
        $this->getJson('/api/v1/messages', $this->enTetes($this->julieToken))->assertOk();
        $this->assertSame(['utilisateur.'.$this->owner->id], $this->channels());
        $this->assertSame('delivered', $this->ownerThread()[0]['status']);

        // Julie ouvre le fil : lu, une seule annonce ; le message de la clinique n'a pas de coches chez le propriétaire
        $this->channels();
        $this->getJson('/api/v1/messages/u'.$this->owner->id, $this->enTetes($this->julieToken))->assertOk()->assertJsonPath('data.messages.0.status', null);
        $this->assertSame(['utilisateur.'.$this->owner->id], $this->channels());
        $this->assertSame('read', $this->ownerThread()[0]['status']);
        $this->getJson('/api/v1/messages/u'.$this->owner->id, $this->enTetes($this->julieToken))->assertOk();
        $this->getJson('/api/v1/messages', $this->enTetes($this->julieToken))->assertOk();
        $this->assertSame([], $this->channels());
    }

    public function test_a_colleague_message_is_delivered_then_read_and_the_sender_is_told(): void
    {
        $morges = Clinique::factory()->create(['nom' => 'Deux Rives Morges', 'societe_id' => $this->lausanne->societe_id]);
        ['employe' => $anne, 'jeton' => $anneToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dre Anne Meyer']);
        /** @var Employe $marc */
        ['employe' => $marc, 'jeton' => $marcToken] = $this->employeAvecJeton($morges, ['nom' => 'Dr Marc Dubois']);

        $this->postJson('/api/v1/messages/e'.$marc->id, ['texte' => 'Tu prends mon 16 h ?'], $this->enTetes($anneToken))->assertCreated()->assertJsonPath('data.status', 'sent');
        Event::fake([MessagesChangent::class]);

        $this->getJson('/api/v1/messages', $this->enTetes($marcToken))->assertOk();
        $this->assertSame(['utilisateur.'.$anne->user_id], $this->channels());
        $this->assertSame('delivered', $this->getJson('/api/v1/messages', $this->enTetes($anneToken))->json('data.0.dernier.status'));

        $this->channels();
        $this->getJson('/api/v1/messages/e'.$anne->id, $this->enTetes($marcToken))->assertOk()->assertJsonPath('data.messages.0.status', null);
        $this->assertSame(['utilisateur.'.$anne->user_id], $this->channels());
        $this->getJson('/api/v1/messages/e'.$marc->id, $this->enTetes($anneToken))->assertJsonPath('data.messages.0.status', 'read');
    }

    public function test_a_message_to_another_clinic_is_read_when_anyone_of_its_team_opens_it(): void
    {
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire de Morges', 'ville' => 'Morges']);
        ['jeton' => $claireToken] = $this->employeAvecJeton($centre, ['nom' => 'Dre Claire Vautier']);
        ['jeton' => $paulToken] = $this->employeAvecJeton($centre, ['nom' => 'Paul Favre']);

        $this->postJson('/api/v1/messages/k'.$centre->id, ['texte' => 'Nous vous adressons Félix.'], $this->enTetes($this->julieToken))->assertCreated()->assertJsonPath('data.status', 'sent');
        Event::fake([MessagesChangent::class]);

        $this->getJson('/api/v1/messages', $this->enTetes($claireToken))->assertOk();
        $this->assertSame(['clinique.'.$this->lausanne->id], $this->channels());
        // Un deuxième membre de l'équipe relit : déjà distribué, personne n'est prévenu
        $this->getJson('/api/v1/messages', $this->enTetes($paulToken))->assertOk();
        $this->assertSame([], $this->channels());

        $this->getJson('/api/v1/messages/k'.$this->lausanne->id, $this->enTetes($paulToken))->assertOk();
        $this->assertSame(['clinique.'.$this->lausanne->id], $this->channels());
        $this->getJson('/api/v1/messages/k'.$centre->id, $this->enTetes($this->julieToken))->assertJsonPath('data.messages.0.status', 'read');
    }

    public function test_the_migration_counts_every_message_already_read_as_delivered(): void
    {
        $this->postJson('/api/v1/messages/u'.$this->owner->id, ['texte' => 'Lu avant les coches.'], $this->enTetes($this->julieToken))->assertCreated();
        $this->postJson('/api/v1/messages/u'.$this->owner->id, ['texte' => 'Pas encore lu.'], $this->enTetes($this->julieToken))->assertCreated();
        [$read, $unread] = MessageClientele::query()->orderBy('id')->get()->all();
        $read->update(['lu_le' => '2026-11-01 10:00:00']);

        $migration = require base_path('database/migrations/2026_11_08_000400_add_delivered_at_to_messages.php');
        $migration->down();
        $migration->up();

        $this->assertSame('2026-11-01 10:00:00', $read->fresh()->delivered_at?->format('Y-m-d H:i:s'));
        $this->assertNull($unread->fresh()->delivered_at);
        $this->getJson('/api/v1/messages/u'.$this->owner->id, $this->enTetes($this->julieToken))
            ->assertJsonPath('data.messages.0.status', 'read')->assertJsonPath('data.messages.1.status', 'sent');
    }
}
