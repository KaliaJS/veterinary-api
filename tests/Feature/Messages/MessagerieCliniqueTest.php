<?php

namespace Tests\Feature\Messages;

use App\Events\MessagesChangent;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MessageClientele;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Messagerie de la clinique (décision de Jim, 28.10.2026 : « Créer la
 * messagerie clinique ») : la clinique lit les messages de ses clients et
 * leur répond, en temps réel ; « Réception des messages clients » et
 * « Réponses uniquement » se règlent par le serveur, pour toute l'équipe.
 */
class MessagerieCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $accueil;

    private User $proprietaire;

    private string $jetonProprietaire;

    private Client $fiche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        ['jeton' => $this->accueil] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller'], 'asv');
        ['user' => $this->proprietaire, 'jeton' => $this->jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->fiche = Client::factory()->create(['user_id' => $this->proprietaire->id, 'organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Léa', 'nom' => 'Martin']);
    }

    private function ecrireCommeProprietaire(string $texte): void
    {
        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $this->clinique->id, 'texte' => $texte], $this->enTetes($this->jetonProprietaire))->assertCreated();
    }

    public function test_la_clinique_voit_les_fils_de_ses_clients_avec_les_non_lus(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');

        $fils = $this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->assertOk()->json('data');

        $this->assertCount(1, $fils);
        $this->assertSame($this->proprietaire->id, $fils[0]['user_id']);
        $this->assertSame('Léa Martin', $fils[0]['client']);
        $this->assertSame(1, $fils[0]['non_lus']);
        $this->assertSame('Félix tousse depuis hier.', $fils[0]['dernier']['texte']);
    }

    public function test_ouvrir_un_fil_le_marque_lu_et_la_clinique_repond(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');
        Event::fake([MessagesChangent::class]);

        $this->getJson("/api/v1/messages/{$this->proprietaire->id}", $this->enTetes($this->accueil))->assertOk()
            ->assertJsonPath('data.messages.0.texte', 'Félix tousse depuis hier.')
            ->assertJsonPath('data.messages.0.auteur', 'proprietaire');
        $this->assertNotNull(MessageClientele::query()->where('auteur', 'proprietaire')->sole()->lu_le);

        $this->postJson("/api/v1/messages/{$this->proprietaire->id}", ['texte' => 'Passez cet après-midi, nous la regardons.'], $this->enTetes($this->accueil))
            ->assertCreated()->assertJsonPath('data.auteur', 'clinique')->assertJsonPath('data.signature', 'Julie Muller');

        // Le propriétaire le voit dans son espace
        $this->getJson("/api/v1/espace/messages?clinique_id={$this->clinique->id}", $this->enTetes($this->jetonProprietaire))
            ->assertJsonPath('data.messages.1.texte', 'Passez cet après-midi, nous la regardons.');
        // En temps réel : son canal est prévenu
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$this->proprietaire->id);
    }

    public function test_un_message_du_proprietaire_previent_la_clinique_en_temps_reel_sans_son_contenu(): void
    {
        Event::fake([MessagesChangent::class]);

        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');

        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id
            && ! str_contains((string) json_encode($e->broadcastWith()), 'tousse'));
    }

    public function test_la_clinique_ouvre_un_fil_avec_un_client_qui_a_un_compte_seulement(): void
    {
        $this->postJson("/api/v1/messages/{$this->proprietaire->id}", ['texte' => 'Le vaccin de Félix arrive à échéance.'], $this->enTetes($this->accueil))->assertCreated();

        // Un compte qui n'est pas client de l'organisation : refusé
        $autre = User::factory()->create();
        $this->postJson("/api/v1/messages/{$autre->id}", ['texte' => 'Bonjour'], $this->enTetes($this->accueil))->assertNotFound();
        $this->getJson("/api/v1/messages/{$autre->id}", $this->enTetes($this->accueil))->assertNotFound();
    }

    public function test_sans_le_droit_patients_la_messagerie_est_fermee(): void
    {
        ['jeton' => $compta, 'employe' => $employe] = $this->employeAvecJeton($this->clinique, ['nom' => 'Compta']);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Compta', 'domaines' => ['facturation']])->id]);

        $this->getJson('/api/v1/messages', $this->enTetes($compta))->assertForbidden();
    }

    public function test_une_clinique_soeur_ne_lit_pas_les_fils_de_l_autre(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $ailleurs] = $this->employeAvecJeton($soeur, [], 'asv');

        $this->assertSame([], $this->getJson('/api/v1/messages', $this->enTetes($ailleurs))->assertOk()->json('data'));
    }

    public function test_reception_des_messages_coupee_la_clinique_ne_recoit_plus_rien(): void
    {
        $this->putJson('/api/v1/rappels/reglages', ['messages_recus' => false], $this->enTetes($this->administrateur()))->assertOk()->assertJsonPath('data.messages_recus', false);

        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $this->clinique->id, 'texte' => 'Bonjour'], $this->enTetes($this->jetonProprietaire))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->getJson('/api/v1/espace/messages', $this->enTetes($this->jetonProprietaire))->assertJsonPath('data.cliniques.0.peut_ecrire', false);
    }

    public function test_reponses_uniquement_le_proprietaire_repond_a_un_fil_ouvert_par_la_clinique(): void
    {
        $this->putJson('/api/v1/rappels/reglages', ['messages_reponses_seulement' => true], $this->enTetes($this->administrateur()))->assertOk();

        // Aucun fil ouvert : pas de nouveau message spontané
        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $this->clinique->id, 'texte' => 'Bonjour'], $this->enTetes($this->jetonProprietaire))->assertUnprocessable();

        $this->postJson("/api/v1/messages/{$this->proprietaire->id}", ['texte' => 'Comment va Félix ?'], $this->enTetes($this->accueil))->assertCreated();
        $this->getJson('/api/v1/espace/messages', $this->enTetes($this->jetonProprietaire))->assertJsonPath('data.cliniques.0.peut_ecrire', true);
        $this->ecrireCommeProprietaire('Il va mieux, merci.');
    }

    public function test_les_trois_reglages_sont_enregistres_par_le_serveur_et_partages_par_l_equipe(): void
    {
        $admin = $this->administrateur();
        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->accueil))->assertOk()
            ->assertJsonPath('data.relance_noshow', false)->assertJsonPath('data.messages_recus', true)->assertJsonPath('data.messages_reponses_seulement', false);

        $this->putJson('/api/v1/rappels/reglages', ['relance_noshow' => true, 'messages_reponses_seulement' => true], $this->enTetes($admin))->assertOk();

        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->accueil))->assertOk()
            ->assertJsonPath('data.relance_noshow', true)->assertJsonPath('data.messages_reponses_seulement', true);
    }

    private function administrateur(): string
    {
        return $this->administrateurAvecJeton($this->clinique)['jeton'];
    }
}
