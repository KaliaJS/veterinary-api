<?php

namespace Tests\Feature\Espace;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use App\Models\Devis;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — accueil : contexte hors clinique, rattachement
 * des fiches par e-mail vérifié, dérivés composés côté serveur, et
 * isolation entre comptes.
 */
class AccueilProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_l_espace_exige_une_session(): void
    {
        $this->getJson('/api/v1/espace/accueil')->assertUnauthorized();
    }

    public function test_un_compte_sans_fiche_a_un_accueil_vide(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'nouveau@exemple.ch']);

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.email', 'nouveau@exemple.ch')
            ->assertJsonPath('data.compte.fiches', [])
            ->assertJsonPath('data.animaux', [])
            ->assertJsonPath('data.rappels', [])
            ->assertJsonPath('data.a_faire', [])
            ->assertJsonPath('data.prochain_rendez_vous', null)
            ->assertJsonPath('data.ordonnance', null)
            ->assertJsonPath('data.derniere_commande', null)
            ->assertJsonPath('data.urgence', null);
    }

    public function test_les_fiches_a_l_email_du_compte_sont_rattachees_seulement_si_l_email_est_verifie(): void
    {
        $fiche = Client::factory()->create(['email' => 'Anna.Meyer@exemple.ch']);
        Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);

        // Inscrit par passkey : adresse non prouvée, rien n'est rattaché.
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'anna.meyer@exemple.ch', 'email_verified_at' => null]);

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.email_verifie', false)
            ->assertJsonPath('data.compte.fiches', [])
            ->assertJsonPath('data.animaux', []);
        $this->assertNull($fiche->fresh()->user_id);

        // Adresse prouvée par code : la fiche et ses animaux apparaissent.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.email_verifie', true)
            ->assertJsonPath('data.compte.fiches.0.id', $fiche->id)
            ->assertJsonPath('data.compte.fiches.0.organisation.id', $fiche->organisation_id)
            ->assertJsonPath('data.animaux.0.nom', 'Max')
            ->assertJsonPath('data.animaux.0.espece_libelle', fn (string $libelle): bool => $libelle !== '');
        $this->assertSame($user->id, $fiche->fresh()->user_id);
    }

    public function test_l_accueil_compose_les_blocs_toutes_cliniques_confondues(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email_verified_at' => now()]);
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $paco = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Paco']);

        // Rendez-vous : un passé, un demain, un dans trois jours — le prochain est celui de demain.
        RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20), 'status' => StatutRendezVous::Fini]);
        $demain = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay()->setTime(14, 30), 'ends_at' => now()->addDay()->setTime(14, 50), 'reason' => 'Contrôle otite']);
        RendezVous::factory()->pourPatient($paco)->create(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20)]);

        // Vaccins : Rage en retard (dernière injection), une injection plus ancienne de Rage
        // ne compte plus ; Leucose à J+12 ; CHPPi à J+300 hors fenêtre.
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => today()->subYears(3), 'echeance_le' => today()->subYears(2)]);
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => today()->subYear()->subDays(8), 'echeance_le' => today()->subDays(8)]);
        Vaccination::factory()->create(['patient_id' => $paco->id, 'nom' => 'Leucose (FeLV)', 'injectee_le' => today()->subYear()->addDays(12), 'echeance_le' => today()->addDays(12)]);
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'CHPPi', 'injectee_le' => today()->subDays(65), 'echeance_le' => today()->addDays(300)]);

        // Devis portail envoyé, non signé → à signer ; un devis papier ne concerne pas le portail.
        $devis = Devis::factory()->create(['patient_id' => $paco->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'signe_le' => null, 'motif' => 'Détartrage']);
        Devis::factory()->create(['patient_id' => $paco->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier, 'signe_le' => null]);

        // Commandes : une retirée hier, une prête aujourd'hui → dernière = la prête, et « à faire ».
        CommandeBoutique::factory()->create(['client_id' => $fiche->id, 'passee_le' => now()->subDay(), 'statut' => 'done']);
        $prete = CommandeBoutique::factory()->create(['client_id' => $fiche->id, 'passee_le' => now(), 'statut' => 'ready']);
        CommandeBoutiqueLigne::factory()->create(['commande_boutique_id' => $prete->id, 'quantite' => 2]);

        $ordonnance = Ordonnance::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id]);

        $reponse = $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertOk();

        $reponse
            ->assertJsonPath('data.prochain_rendez_vous.id', $demain->id)
            ->assertJsonPath('data.prochain_rendez_vous.motif', 'Contrôle otite')
            ->assertJsonPath('data.prochain_rendez_vous.patient.nom', 'Max')
            ->assertJsonPath('data.prochain_rendez_vous.clinique.id', $demain->clinique_id)
            ->assertJsonCount(2, 'data.rappels')
            ->assertJsonPath('data.rappels.0.vaccin', 'Rage')
            ->assertJsonPath('data.rappels.0.jours', -8)
            ->assertJsonPath('data.rappels.0.est_en_retard', true)
            ->assertJsonPath('data.rappels.1.vaccin', 'Leucose (FeLV)')
            ->assertJsonPath('data.rappels.1.jours', 12)
            ->assertJsonPath('data.rappels.1.patient.nom', 'Paco')
            ->assertJsonPath('data.a_faire.0.type', 'devis')
            ->assertJsonPath('data.a_faire.0.devis_id', $devis->id)
            ->assertJsonPath('data.a_faire.1.type', 'commande')
            ->assertJsonPath('data.a_faire.1.commande_id', $prete->id)
            ->assertJsonPath('data.a_faire.2.type', 'rappel')
            ->assertJsonPath('data.a_faire.2.libelle', 'Réserver le rappel vaccin (2)')
            ->assertJsonPath('data.animaux.0.nom', 'Max')
            ->assertJsonPath('data.animaux.1.nom', 'Paco')
            ->assertJsonPath('data.ordonnance.id', $ordonnance->id)
            ->assertJsonPath('data.ordonnance.patient.nom', 'Max')
            ->assertJsonPath('data.derniere_commande.id', $prete->id)
            ->assertJsonPath('data.derniere_commande.statut_libelle', 'Prête au retrait')
            ->assertJsonPath('data.urgence.clinique.id', $demain->clinique_id);

        $this->assertStringContainsString('×2', $reponse->json('data.derniere_commande.articles'));
        $this->assertStringStartsWith('Signer le devis détartrage (CHF ', $reponse->json('data.a_faire.0.libelle'));
    }

    public function test_les_fiches_d_un_autre_compte_restent_invisibles(): void
    {
        $autre = User::factory()->create();
        $ficheAutre = Client::factory()->create(['user_id' => $autre->id]);
        Patient::factory()->create(['client_id' => $ficheAutre->id, 'nom' => 'Intrus']);

        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['email_verified_at' => now()]);

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.fiches', [])
            ->assertJsonPath('data.animaux', []);
    }

    /** Un employé peut aussi être propriétaire : son jeton clinique ouvre l'espace. */
    public function test_un_jeton_clinique_ouvre_aussi_l_espace(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.fiches.0.id', $fiche->id);
    }

    public function test_la_verification_de_l_email_rattache_les_fiches_en_attente(): void
    {
        Notification::fake();

        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email' => 'anna@exemple.ch', 'email_verified_at' => null]);
        $fiche = Client::factory()->create(['email' => 'anna@exemple.ch']);

        $this->postJson('/api/v1/espace/email/envoyer', [], $this->enTetes($jeton))->assertStatus(202);

        $code = null;
        Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/espace/email/verifier', ['code' => '000000'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/espace/email/verifier', ['code' => $code], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('email_verifie', true)
            ->assertJsonPath('fiches_rattachees', 1);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame($user->id, $fiche->fresh()->user_id);

        // Déjà vérifiée : plus d'envoi.
        $this->postJson('/api/v1/espace/email/envoyer', [], $this->enTetes($jeton))->assertOk();
    }
}
