<?php

namespace Tests\Feature\Espace;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Document;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — documents & factures : liste composée par type,
 * signature de devis par le propriétaire, demande de renouvellement.
 */
class DocumentsProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_liste_compose_ordonnances_factures_devis_carnets_et_documents(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);

        $ordonnance = Ordonnance::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'renouvellements' => 2, 'signee_le' => aujourdhui()->subDays(10)]);
        $facture = Facture::factory()->create(['client_id' => $fiche->id, 'patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutFacture::Payee, 'emise_le' => now()->subDays(5)]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => 'Consultation', 'quantite' => 1, 'prix_chf' => 85]);
        $devis = Devis::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'signe_le' => null, 'motif' => 'Détartrage', 'emis_le' => aujourdhui()->subDays(2)]);
        Devis::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier]);
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => aujourdhui()->subDays(20)]);
        Document::factory()->create(['patient_id' => $max->id, 'nom' => 'Radiographie thorax', 'type' => 'imagerie', 'etabli_le' => aujourdhui()->subDays(40)]);
        Ordonnance::factory()->create(); // un autre propriétaire

        $reponse = $this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk();
        $items = collect($reponse->json('data'));

        $this->assertCount(5, $items);
        // Le devis à signer vient en tête, quel que soit son âge.
        $this->assertSame('DEVIS', $items[0]['type']);
        $this->assertSame($devis->id, $items[0]['id']);
        $this->assertTrue($items[0]['a_signer']);
        $this->assertSame('EN ATTENTE DE VOTRE SIGNATURE', $items[0]['sous_ligne']);
        $this->assertStringStartsWith('Devis détartrage, CHF ', $items[0]['titre']);

        $parType = $items->keyBy('type');
        $this->assertStringStartsWith('Facture consultation, CHF 85.00', $parType['FACTURE']['titre']);
        $this->assertStringStartsWith('PAYÉE · ', $parType['FACTURE']['sous_ligne']);
        $this->assertEquals(85, $parType['FACTURE']['pdf']['total_ttc']);
        // Chaque pièce porte l'en-tête de la clinique qui l'a établie
        $this->assertNotEmpty($parType['FACTURE']['pdf']['en_tete']['nom']);
        $this->assertNotEmpty($parType['DEVIS']['pdf']['en_tete']['nom']);
        $this->assertSame($ordonnance->id, $parType['ORDONNANCE']['id']);
        $this->assertTrue($parType['ORDONNANCE']['peut_renouveler']);
        $this->assertSame('Carnet de vaccination : Max', $parType['CARNET']['titre']);
        $this->assertCount(1, $parType['CARNET']['pdf']['vaccinations']);
        $this->assertSame('Radiographie thorax (Max)', $parType['IMAGERIE']['titre']);
        $this->assertNull($parType['IMAGERIE']['pdf']);
    }

    public function test_le_proprietaire_signe_son_devis_du_portail(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id, 'prenom' => 'Anna', 'nom' => 'Meyer']);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        $devis = Devis::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'signe_le' => null]);

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'accepte');

        $devis->refresh();
        $this->assertNotNull($devis->signe_le);
        $this->assertStringContainsString('espace client', $devis->signe_par);

        // Déjà signé : refusé. Un devis papier, ou d'un autre compte : introuvable.
        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Ce devis n’est plus en attente de signature.']);
        $papier = Devis::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier]);
        $this->postJson("/api/v1/espace/devis/{$papier->id}/signer", [], $this->enTetes($jeton))->assertNotFound();
        $autre = Devis::factory()->create(['statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail]);
        $this->postJson("/api/v1/espace/devis/{$autre->id}/signer", [], $this->enTetes($jeton))->assertNotFound();
    }

    public function test_qui_gere_un_animal_reclame_ne_signe_pas_au_nom_du_client_de_la_fiche(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        // La fiche est celle d'un autre client ; le compte gère l'animal (réclamé par puce)
        $ficheDUnAutre = Client::factory()->create();
        $rex = Patient::factory()->create(['client_id' => $ficheDUnAutre->id, 'proprietaire_id' => $user->id]);
        $devis = Devis::factory()->create(['patient_id' => $rex->id, 'organisation_id' => $ficheDUnAutre->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail]);

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", [], $this->enTetes($jeton))->assertNotFound();
        $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut);
    }

    public function test_la_demande_de_renouvellement_est_unique_et_traitee_par_le_renouvellement_clinique(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        $ordonnance = Ordonnance::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'renouvellements' => 1]);

        $this->postJson("/api/v1/espace/ordonnances/{$ordonnance->id}/renouvellement", [], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.ordonnance_id', $ordonnance->id);
        $this->assertDatabaseHas('demandes_renouvellement_ordonnance', ['ordonnance_id' => $ordonnance->id, 'user_id' => $user->id, 'traitee_le' => null]);

        // Une seule demande en attente ; la liste et l'accueil le reflètent.
        $this->postJson("/api/v1/espace/ordonnances/{$ordonnance->id}/renouvellement", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Une demande de renouvellement est déjà en attente.']);
        $this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))
            ->assertJsonPath('data.0.sous_ligne', 'RENOUVELLEMENT DEMANDÉ · EN ATTENTE')
            ->assertJsonPath('data.0.peut_renouveler', false);
        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertJsonPath('data.ordonnance.renouvellement_en_attente', true)
            ->assertJsonPath('data.ordonnance.peut_renouveler', false);

        // Plus de renouvellement prévu : refusé.
        $epuisee = Ordonnance::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'renouvellements' => 0]);
        $this->postJson("/api/v1/espace/ordonnances/{$epuisee->id}/renouvellement", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('renouvellements');
    }
}
