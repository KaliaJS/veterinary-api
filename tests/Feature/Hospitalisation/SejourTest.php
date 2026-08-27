<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\StatutSejour;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cycle de vie d'un séjour (docs/data-model.md §8) : admission avec
 * plan de soins d'entrée et box borné à la capacité, soins faits avec
 * sortie de stock journalisée, constantes, sortie avec facture du
 * séjour — le séjour est clos, jamais supprimé.
 */
class SejourTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    private function sejour(array $attributs = []): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            ...$attributs,
        ]);
    }

    public function test_l_admission_cree_le_sejour_avec_son_plan_de_soins_d_entree(): void
    {
        $reponse = $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 3,
            'motif' => 'Gastro-entérite hémorragique — réhydratation',
            'statut' => 'surv',
        ], $this->enTetes($this->jeton))->assertCreated();

        $reponse->assertJsonPath('data.box', 3)
            ->assertJsonPath('data.statut', 'surv')
            ->assertJsonPath('data.sortie_prevue', 'À définir')
            ->assertJsonCount(3, 'data.soins');

        $sejour = Sejour::withoutGlobalScopes()->sole();

        // Le vétérinaire référent par défaut est l'employé du jeton.
        $this->assertSame($this->employe->id, $sejour->veterinaire_id);
        $this->assertNotNull($sejour->admis_le);
        $this->assertNull($sejour->sorti_le);
    }

    public function test_un_box_occupe_par_un_sejour_en_cours_refuse_l_admission(): void
    {
        $this->sejour(['box' => 4]);

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 4,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('box');
    }

    public function test_un_box_sorti_se_reoccupe(): void
    {
        $this->sejour(['box' => 4, 'sorti_le' => now()]);

        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 4,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))->assertCreated();
    }

    public function test_un_box_au_dela_de_la_capacite_de_la_clinique_est_refuse(): void
    {
        $this->postJson('/api/v1/hospitalisation/sejours', [
            'patient_id' => $this->patient->id,
            'box' => 9,
            'motif' => 'Surveillance',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('box');
    }

    public function test_la_liste_ne_montre_que_les_sejours_en_cours_sauf_historique(): void
    {
        $this->sejour(['box' => 1]);
        $this->sejour(['box' => 2, 'sorti_le' => now()]);

        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/hospitalisation/sejours?historique=1', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_un_soin_fait_sort_le_stock_de_la_clinique_et_se_journalise(): void
    {
        $sejour = $this->sejour();
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 5]);

        $soin = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", [
            'prevu_a' => 630,
            'acte' => 'Méloxicam 0,1 mg/kg IV',
            'produit_id' => $produit->id,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin}/faire", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fait', true)
            ->assertJsonPath('stock.sorti', true)
            ->assertJsonPath('stock.rupture', false);

        $this->assertSame(4, Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole()->quantite);

        $mouvement = MouvementStock::withoutGlobalScopes()->sole();
        $this->assertSame(-1, $mouvement->quantite);
        $this->assertStringContainsString('Soin d\'hospitalisation', $mouvement->libelle);
    }

    public function test_un_soin_dont_le_produit_est_en_rupture_reste_fait_et_signale_la_rupture(): void
    {
        $sejour = $this->sejour();
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        $soin = $sejour->soins()->create(['prevu_a' => 600, 'acte' => 'Antibiotique', 'produit_id' => $produit->id]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin->id}/faire", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fait', true)
            ->assertJsonPath('stock.rupture', true);

        $this->assertDatabaseCount('mouvements_stock', 0);
    }

    public function test_un_soin_deja_fait_ne_se_refait_pas(): void
    {
        $sejour = $this->sejour();
        $soin = $sejour->soins()->create(['prevu_a' => 600, 'acte' => 'Contrôle', 'fait' => true, 'fait_le' => now()]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin->id}/faire", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();
    }

    public function test_les_constantes_s_enregistrent_au_chevet(): void
    {
        $sejour = $this->sejour();

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/constantes", [
            'mesure_a' => 480,
            'temperature' => 38.4,
            'fc' => 88,
            'fr' => 24,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.temperature', '38.4');
    }

    public function test_le_suivi_du_sejour_se_modifie_en_cours(): void
    {
        $sejour = $this->sejour(['statut' => StatutSejour::Stable]);

        $this->patchJson("/api/v1/hospitalisation/sejours/{$sejour->id}", [
            'statut' => 'critique',
            'perfusion' => 'NaCl 0,9 % · 80 ml/h',
            'note' => 'Surveillance rapprochée cette nuit.',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'critique')
            ->assertJsonPath('data.perfusion', 'NaCl 0,9 % · 80 ml/h');
    }

    public function test_la_sortie_facture_le_sejour_et_libere_le_box_sans_le_supprimer(): void
    {
        $sejour = $this->sejour([
            'box' => 2,
            'statut' => StatutSejour::Surveillance,
            'perfusion' => 'Ringer lactate · 45 ml/h',
            'admis_le' => now()->subDay(),
        ]);
        $sejour->soins()->create(['prevu_a' => 480, 'acte' => 'Contrôle', 'fait' => true, 'fait_le' => now()]);
        $sejour->soins()->create(['prevu_a' => 600, 'acte' => 'Pansement', 'fait' => true, 'fait_le' => now()]);
        $sejour->soins()->create(['prevu_a' => 900, 'acte' => 'Repas', 'fait' => false]);

        $reponse = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $this->enTetes($this->jeton))
            ->assertOk();

        $reponse->assertJsonPath('facture.statut', 'due');

        $sejour->refresh();
        $this->assertNotNull($sejour->sorti_le);
        $this->assertNotNull($sejour->facture_id);

        $facture = Facture::withoutGlobalScopes()->sole();
        $lignes = $facture->lignes()->orderBy('id')->get();

        // Admis hier = 2 jours : forfait 95 × 2, perfusion 38 × 2,
        // 2 soins faits × 15 — total 296 CHF.
        $this->assertCount(3, $lignes);
        $this->assertSame(2, $lignes[0]->quantite);
        $this->assertSame(2, $lignes[1]->quantite);
        $this->assertStringContainsString('surveillance rapprochée, 2 jours', $lignes[0]->libelle);
        $this->assertSame(95.0, (float) $lignes[0]->prix_chf);
        $this->assertSame(38.0, (float) $lignes[1]->prix_chf);
        $this->assertSame(2, $lignes[2]->quantite);
        $this->assertSame(15.0, (float) $lignes[2]->prix_chf);
        $this->assertSame(296.0, $facture->totalTtc());

        // Le séjour sort des écrans mais reste au dossier.
        $this->getJson('/api/v1/hospitalisation/sejours', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('sejours', 1);
    }

    public function test_l_apercu_de_facture_ne_cree_rien(): void
    {
        $sejour = $this->sejour(['statut' => StatutSejour::Critique, 'admis_le' => now()]);

        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}/facture", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.jours', 1)
            ->assertJsonPath('data.total_chf', 140);

        $this->assertDatabaseCount('factures', 0);
    }

    public function test_un_sejour_sorti_ne_se_modifie_plus_et_ne_se_ressort_pas(): void
    {
        $sejour = $this->sejour(['sorti_le' => now()]);

        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/hospitalisation/sejours/{$sejour->id}", ['note' => 'x'], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", ['prevu_a' => 600, 'acte' => 'x'], $entetes)->assertUnprocessable();
    }

    public function test_aucune_route_de_suppression_de_sejour(): void
    {
        $sejour = $this->sejour();

        $this->deleteJson("/api/v1/hospitalisation/sejours/{$sejour->id}", [], $this->enTetes($this->jeton))
            ->assertStatus(405);
    }
}
