<?php

namespace Tests\Feature\Messages;

use App\Events\MessagesChangent;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MessageClientele;
use App\Models\PreferencesProprietaire;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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
        $this->assertSame('u'.$this->proprietaire->id, $fils[0]['cle']);
        $this->assertTrue($fils[0]['peut_repondre']);
        $this->assertSame('Léa Martin', $fils[0]['client']);
        $this->assertSame(1, $fils[0]['non_lus']);
        $this->assertSame('Félix tousse depuis hier.', $fils[0]['dernier']['texte']);
    }

    public function test_ouvrir_un_fil_le_marque_lu_et_la_clinique_repond(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');
        Event::fake([MessagesChangent::class]);

        $this->getJson("/api/v1/messages/u{$this->proprietaire->id}", $this->enTetes($this->accueil))->assertOk()
            ->assertJsonPath('data.messages.0.texte', 'Félix tousse depuis hier.')
            ->assertJsonPath('data.messages.0.auteur', 'proprietaire');
        $this->assertNotNull(MessageClientele::query()->where('auteur', 'proprietaire')->sole()->lu_le);

        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Passez cet après-midi, nous la regardons.'], $this->enTetes($this->accueil))
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
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Le vaccin de Félix arrive à échéance.'], $this->enTetes($this->accueil))->assertCreated();

        // Un compte qui n'est pas client de l'organisation : refusé
        $autre = User::factory()->create();
        $this->postJson("/api/v1/messages/u{$autre->id}", ['texte' => 'Bonjour'], $this->enTetes($this->accueil))->assertNotFound();
        $this->getJson("/api/v1/messages/u{$autre->id}", $this->enTetes($this->accueil))->assertNotFound();
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

        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Comment va Félix ?'], $this->enTetes($this->accueil))->assertCreated();
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

    /** Un compte effacé : son fil reste lisible par sa fiche, ses non-lus se lisent, on n'y répond plus */
    public function test_le_fil_d_un_compte_efface_se_lit_par_sa_fiche_sans_reponse_possible(): void
    {
        MessageClientele::create(['clinique_id' => $this->clinique->id, 'user_id' => null, 'client_id' => $this->fiche->id, 'auteur' => 'proprietaire', 'texte' => 'Avant de partir, merci pour tout.']);

        $fil = collect($this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->assertOk()->json('data'))->firstWhere('cle', 'c'.$this->fiche->id);
        $this->assertSame(['Léa Martin', 1, false], [$fil['client'], $fil['non_lus'], $fil['peut_repondre']]);

        $this->getJson('/api/v1/messages/c'.$this->fiche->id, $this->enTetes($this->accueil))->assertOk()->assertJsonPath('data.messages.0.texte', 'Avant de partir, merci pour tout.')
            ->assertJsonPath('data.peut_repondre', false);
        $this->assertSame(0, collect($this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->json('data'))->firstWhere('cle', 'c'.$this->fiche->id)['non_lus']);
        $this->postJson('/api/v1/messages/c'.$this->fiche->id, ['texte' => 'Bonjour'], $this->enTetes($this->accueil))->assertUnprocessable();
    }

    /** Un compte révoqué (fiche détachée) : son fil s'ouvre encore, la réponse est refusée */
    public function test_le_fil_d_un_compte_revoque_s_ouvre_sans_reponse_possible(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');
        $this->fiche->update(['user_id' => null]);

        $fil = collect($this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->assertOk()->json('data'))->firstWhere('cle', 'u'.$this->proprietaire->id);
        $this->assertFalse($fil['peut_repondre']);
        $this->getJson("/api/v1/messages/u{$this->proprietaire->id}", $this->enTetes($this->accueil))->assertOk()->assertJsonPath('data.messages.0.texte', 'Félix tousse depuis hier.');
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Bonjour'], $this->enTetes($this->accueil))->assertUnprocessable();
    }

    /** La clinique ouvre un fil depuis la fiche d'un client qui a un compte, sans message encore */
    public function test_la_clinique_ouvre_un_fil_vide_avec_un_client_qui_a_un_compte(): void
    {
        $this->getJson("/api/v1/messages/u{$this->proprietaire->id}", $this->enTetes($this->accueil))->assertOk()
            ->assertJsonPath('data.client', 'Léa Martin')->assertJsonPath('data.messages', [])->assertJsonPath('data.peut_repondre', true);
    }

    /** La réponse de la clinique prévient le propriétaire par e-mail (réglage « Réponses de la clinique »), sauf s'il l'a coupé */
    public function test_la_reponse_previent_le_proprietaire_par_e_mail_sauf_s_il_l_a_coupe(): void
    {
        Mail::fake();
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Passez cet après-midi.'], $this->enTetes($this->accueil))->assertCreated();
        Mail::assertSent(ReponseClinique::class, fn (ReponseClinique $mail): bool => $mail->hasTo($this->proprietaire->email)
            && str_contains($mail->render(), 'Deux Rives') && str_contains($mail->render(), 'Passez cet après-midi.'));

        PreferencesProprietaire::pour($this->proprietaire)->update(['reponses_clinique' => false]);
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Et demain ?'], $this->enTetes($this->accueil))->assertCreated();
        Mail::assertSent(ReponseClinique::class, 1);
    }

    /** Le bouton de l'e-mail ouvre le fil de cette clinique dans l'espace du propriétaire */
    public function test_le_lien_de_l_e_mail_ouvre_le_fil_de_la_clinique_dans_l_espace(): void
    {
        Mail::fake();
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Passez cet après-midi.'], $this->enTetes($this->accueil))->assertCreated();

        $attendu = rtrim((string) config('app.frontend_url'), '/').'/espace/messages?clinique='.$this->clinique->id;
        Mail::assertSent(ReponseClinique::class, fn (ReponseClinique $mail): bool => str_contains($mail->render(), $attendu));
    }

    /** Une clinique inconnue dans le lien : le propriétaire tombe sur le fil de sa première clinique, pas sur un écran vide */
    public function test_une_clinique_inconnue_dans_le_lien_ouvre_la_premiere_clinique(): void
    {
        $this->ecrireCommeProprietaire('Félix tousse depuis hier.');

        $this->getJson('/api/v1/espace/messages?clinique_id=999999', $this->enTetes($this->jetonProprietaire))->assertOk()
            ->assertJsonPath('data.clinique_id', $this->clinique->id)->assertJsonPath('data.messages.0.texte', 'Félix tousse depuis hier.');
    }

    /** Un message d'avant le 27.10.2026, sans fiche : la migration lui pose celle du compte, le fil d'un compte détaché garde son nom */
    public function test_les_anciens_messages_recoivent_la_fiche_du_compte(): void
    {
        $ancien = MessageClientele::create(['clinique_id' => $this->clinique->id, 'user_id' => $this->proprietaire->id, 'client_id' => null, 'auteur' => 'proprietaire', 'texte' => 'Message d’avant.']);
        $autre = Client::factory()->create(['user_id' => $this->proprietaire->id, 'prenom' => 'Léa', 'nom' => 'Ailleurs']);

        (require database_path('migrations/2026_10_28_000015_fiche_des_anciens_messages.php'))->up();

        $this->assertSame($this->fiche->id, $ancien->refresh()->client_id);
        $this->assertNotSame($autre->organisation_id, $this->fiche->organisation_id);
        $this->fiche->update(['user_id' => null]);
        $fil = collect($this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->assertOk()->json('data'))->firstWhere('cle', 'u'.$this->proprietaire->id);
        $this->assertSame('Léa Martin', $fil['client']);
        $this->getJson('/api/v1/messages/u'.$this->proprietaire->id, $this->enTetes($this->accueil))->assertOk()->assertJsonPath('data.messages.0.texte', 'Message d’avant.');
    }

    /** Plus de 500 anciens messages : la migration les reprend tous (lecture par identifiant, pas par décalage) */
    public function test_la_migration_reprend_tous_les_anciens_messages_au_dela_d_une_page(): void
    {
        $lignes = [];
        for ($i = 0; $i < 600; $i++) {
            $lignes[] = ['clinique_id' => $this->clinique->id, 'user_id' => $this->proprietaire->id, 'client_id' => null, 'auteur' => 'proprietaire', 'texte' => 'Ancien '.$i, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('messages_clientele')->insert($lignes);

        (require database_path('migrations/2026_10_28_000015_fiche_des_anciens_messages.php'))->up();

        $this->assertSame(0, DB::table('messages_clientele')->whereNull('client_id')->count());
    }

    /** Resend en panne : la réponse est enregistrée et annoncée, l'employé ne voit pas d'erreur (pas de doublon en renvoyant) */
    public function test_une_panne_de_l_e_mail_n_empeche_pas_la_reponse(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Resend en panne'));

        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Passez cet après-midi.'], $this->enTetes($this->accueil))->assertCreated();

        $this->assertSame(1, MessageClientele::query()->where('auteur', 'clinique')->count());
    }

    /** Décision de Jim (28.10.2026, choix B) : « Réponses uniquement », le propriétaire répond dans les 14 jours après le dernier message de la clinique */
    public function test_reponses_uniquement_la_reponse_est_ouverte_14_jours_apres_le_dernier_message_de_la_clinique(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Zurich'));
        $this->putJson('/api/v1/rappels/reglages', ['messages_reponses_seulement' => true], $this->enTetes($this->administrateur()))->assertOk();
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Le rappel de vaccin de Félix arrive.'], $this->enTetes($this->accueil))->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Zurich'));
        $this->ecrireCommeProprietaire('Merci, je prends rendez-vous.');

        // 14 jours pile moins une minute : encore ouvert
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:59', 'Europe/Zurich'));
        $this->ecrireCommeProprietaire('Et le vermifuge ?');

        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:01', 'Europe/Zurich'));
        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $this->clinique->id, 'texte' => 'Autre chose : Moka boite.'], $this->enTetes($this->jetonProprietaire))->assertUnprocessable();
        $fil = $this->getJson('/api/v1/espace/messages', $this->enTetes($this->jetonProprietaire))->assertOk()->json('data.cliniques.0');
        $this->assertFalse($fil['peut_ecrire']);
        $this->assertStringContainsString('14 jours', $fil['raison']);
        $this->assertStringContainsString('est passé', $fil['raison']);

        // La clinique réécrit : la réponse se rouvre
        $this->postJson("/api/v1/messages/u{$this->proprietaire->id}", ['texte' => 'Comment va Moka ?'], $this->enTetes($this->accueil))->assertCreated();
        $this->ecrireCommeProprietaire('Elle boite encore un peu.');
    }
}
