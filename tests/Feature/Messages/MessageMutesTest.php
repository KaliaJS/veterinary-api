<?php

namespace Tests\Feature\Messages;

use App\Enums\Espece;
use App\Events\MessagesChangent;
use App\Jobs\SendPush;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Messages façon WhatsApp (décisions de Jim, 08.11.2026) : le mode
 * silencieux d'une conversation, pour une personne sur tous ses appareils ;
 * l'en-tête et les infos d'une conversation (téléphone, animaux, rôle,
 * adresse, nombre d'audios et de vidéos).
 */
class MessageMutesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $lausanne;

    private string $julieToken;

    private User $owner;

    private Client $fiche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lausanne = Clinique::factory()->create(['nom' => 'Deux Rives Lausanne', 'ville' => 'Lausanne', 'tel' => '021 600 00 00']);
        ['jeton' => $this->julieToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Julie Muller'], 'asv');
        ['user' => $this->owner] = $this->proprietaireAvecJeton();
        $this->fiche = Client::factory()->create(['user_id' => $this->owner->id, 'organisation_id' => $this->lausanne->societe->organisation_id, 'prenom' => 'Edward', 'nom' => 'Fournier', 'tel' => '079 412 33 08']);
    }

    private function threads(string $token): array
    {
        return collect($this->getJson('/api/v1/messages', $this->enTetes($token))->assertOk()->json('data'))->keyBy('cle')->all();
    }

    public function test_a_muted_colleague_conversation_sends_no_push_and_says_it_is_muted(): void
    {
        $morges = Clinique::factory()->create(['nom' => 'Deux Rives Morges', 'societe_id' => $this->lausanne->societe_id]);
        ['employe' => $anne, 'jeton' => $anneToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dre Anne Meyer']);
        ['employe' => $marc, 'jeton' => $marcToken] = $this->employeAvecJeton($morges, ['nom' => 'Dr Marc Dubois']);
        $this->postJson('/api/v1/messages/e'.$marc->id, ['texte' => 'Bonjour'], $this->enTetes($anneToken))->assertCreated();

        $this->putJson('/api/v1/messages/e'.$anne->id.'/silence', ['muted' => true], $this->enTetes($marcToken))->assertOk()->assertJsonPath('data.muted', true);
        $this->assertTrue($this->threads($marcToken)['e'.$anne->id]['muted']);
        $this->getJson('/api/v1/messages/e'.$anne->id, $this->enTetes($marcToken))->assertJsonPath('data.muted', true);
        // Pour lui seul : Anne n'a rien mis en silence
        $this->assertFalse($this->threads($anneToken)['e'.$marc->id]['muted']);

        Queue::fake([SendPush::class]);
        $this->postJson('/api/v1/messages/e'.$marc->id, ['texte' => 'Tu prends mon 16 h ?'], $this->enTetes($anneToken))->assertCreated();
        Queue::assertNotPushed(SendPush::class);

        // Le silence le suit d'une clinique à l'autre ; levé, les notifications reviennent
        $this->putJson('/api/v1/messages/e'.$anne->id.'/silence', ['muted' => false], $this->enTetes($marcToken))->assertOk();
        $this->postJson('/api/v1/messages/e'.$marc->id, ['texte' => 'Alors ?'], $this->enTetes($anneToken))->assertCreated();
        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $marc->user_id);
    }

    public function test_in_another_clinic_only_those_who_muted_the_channel_get_no_push(): void
    {
        $centre = Clinique::factory()->create(['nom' => 'Centre vétérinaire de Morges', 'ville' => 'Morges', 'rue' => 'Rue du Lac 12', 'npa' => '1110', 'tel' => '021 801 22 40']);
        ['employe' => $claire, 'jeton' => $claireToken] = $this->employeAvecJeton($centre, ['nom' => 'Dre Claire Vautier']);
        ['employe' => $paul] = $this->employeAvecJeton($centre, ['nom' => 'Paul Favre']);
        $this->postJson('/api/v1/messages/k'.$centre->id, ['texte' => 'Bonjour'], $this->enTetes($this->julieToken))->assertCreated();
        $this->putJson('/api/v1/messages/k'.$this->lausanne->id.'/silence', ['muted' => true], $this->enTetes($claireToken))->assertOk();

        Queue::fake([SendPush::class]);
        $this->postJson('/api/v1/messages/k'.$centre->id, ['texte' => 'Nous vous adressons Félix.'], $this->enTetes($this->julieToken))->assertCreated();
        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $paul->user_id);
        Queue::assertNotPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $claire->user_id);

        // Les infos de la clinique, vues de Lausanne
        $this->getJson('/api/v1/messages/k'.$centre->id, $this->enTetes($this->julieToken))->assertOk()
            ->assertJsonPath('data.tel', '021 801 22 40')->assertJsonPath('data.address', 'Rue du Lac 12, 1110 Morges')->assertJsonPath('data.muted', false);
    }

    public function test_a_client_conversation_is_muted_for_one_person_and_tells_its_animals_and_phone(): void
    {
        ['jeton' => $anneToken] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dre Anne Meyer']);
        Patient::factory()->create(['client_id' => $this->fiche->id, 'organisation_id' => $this->fiche->organisation_id, 'nom' => 'Bagheera', 'espece' => Espece::Chien, 'race' => 'Jack Russell', 'sexe' => 'Mâle', 'ne_le' => now()->subYears(6)->subWeek()]);
        Patient::factory()->create(['client_id' => $this->fiche->id, 'organisation_id' => $this->fiche->organisation_id, 'nom' => 'Zorro', 'decede_le' => now()->subYear()]);
        $cle = 'u'.$this->owner->id;
        $this->postJson("/api/v1/messages/{$cle}", ['texte' => 'Bonjour'], $this->enTetes($this->julieToken))->assertCreated();

        $this->putJson("/api/v1/messages/{$cle}/silence", ['muted' => true], $this->enTetes($this->julieToken))->assertOk();
        $this->assertTrue($this->threads($this->julieToken)[$cle]['muted']);
        $this->assertFalse($this->threads($anneToken)[$cle]['muted']);

        $fil = $this->getJson("/api/v1/messages/{$cle}", $this->enTetes($this->julieToken))->assertOk()->json('data');
        $this->assertSame(['079 412 33 08', true, 0], [$fil['tel'], $fil['muted'], $fil['media_count']]);
        // Les animaux vivants seulement, décrits par le serveur
        $this->assertSame([['name' => 'Bagheera', 'detail' => 'Jack Russell, mâle, 6 ans']], array_map(fn (array $a): array => ['name' => $a['name'], 'detail' => $a['detail']], $fil['animals']));
    }

    public function test_one_mutes_only_a_conversation_that_exists_here(): void
    {
        $ailleurs = Clinique::factory()->create();
        ['employe' => $etranger] = $this->employeAvecJeton($ailleurs);

        $this->putJson('/api/v1/messages/u999999/silence', ['muted' => true], $this->enTetes($this->julieToken))->assertNotFound();
        $this->putJson('/api/v1/messages/e'.$etranger->id.'/silence', ['muted' => true], $this->enTetes($this->julieToken))->assertNotFound();
        $this->putJson('/api/v1/messages/u'.$this->owner->id.'/silence', [], $this->enTetes($this->julieToken))->assertUnprocessable();
        $this->assertDatabaseCount('message_mutes', 0);
    }

    public function test_the_colleague_header_tells_role_and_clinics_and_the_phone_only_to_who_sees_the_team(): void
    {
        ['employe' => $anne] = $this->employeAvecJeton($this->lausanne, ['nom' => 'Dre Anne Meyer', 'role_libelle' => 'Vétérinaire', 'tel' => '079 201 44 10']);
        ['jeton' => $adminToken] = $this->administrateurAvecJeton($this->lausanne);
        $julie = Employe::query()->where('nom', 'Julie Muller')->sole();
        DerogationPermission::query()->create(['employe_id' => $julie->id, 'domaine' => 'equipe', 'est_accordee' => false]);

        // Julie, ASV à qui l'on a retiré le droit Équipe : le rôle, pas le téléphone (un champ RH), ni dans le fil ni dans la liste
        $this->getJson('/api/v1/messages/e'.$anne->id, $this->enTetes($this->julieToken))->assertOk()
            ->assertJsonPath('data.role', 'Vétérinaire, Deux Rives Lausanne')->assertJsonPath('data.tel', null)->assertJsonPath('data.media_count', 0);
        $this->postJson('/api/v1/messages/e'.$anne->id, ['texte' => 'Bonjour'], $this->enTetes($this->julieToken))->assertCreated();
        $this->assertNull($this->threads($this->julieToken)['e'.$anne->id]['tel']);
        // L'administrateur, qui voit l'équipe : son téléphone
        $this->getJson('/api/v1/messages/e'.$anne->id, $this->enTetes($adminToken))->assertOk()->assertJsonPath('data.tel', '079 201 44 10');
    }

    public function test_muting_tells_the_other_devices_of_this_person_without_content(): void
    {
        Event::fake([MessagesChangent::class]);
        $this->putJson('/api/v1/messages/u'.$this->owner->id.'/silence', ['muted' => true], $this->enTetes($this->julieToken))->assertOk();

        $julie = Employe::query()->where('nom', 'Julie Muller')->sole();
        Event::assertDispatched(MessagesChangent::class, fn ($e): bool => $e->canal === 'utilisateur.'.$julie->user_id);
    }
}
