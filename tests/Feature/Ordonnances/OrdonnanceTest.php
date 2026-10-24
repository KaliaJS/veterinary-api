<?php

namespace Tests\Feature\Ordonnances;

use App\Enums\StatutOrdonnance;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Registre des ordonnances : la signature crée ET sort le stock ;
 * jamais de modification après signature — renouvellement (nouvelle
 * ordonnance, l'ancienne clôturée, sans nouvelle sortie de stock),
 * clôture, ou annulation immédiate dans la fenêtre (stock restauré).
 */
class OrdonnanceTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function medicamentEnStock(int $quantite): Produit
    {
        $produit = Produit::factory()->medicament()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => $quantite,
        ]);

        return $produit;
    }

    private function stockDe(Produit $produit): int
    {
        return Stock::withoutGlobalScopes()
            ->where('produit_id', $produit->id)
            ->where('clinique_id', $this->clinique->id)
            ->sole()
            ->quantite;
    }

    /** Imprimée depuis une clinique sœur : l'en-tête est celui de la clinique qui l'a établie. */
    public function test_l_ordonnance_porte_l_en_tete_de_sa_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Clinique du Lac', 'raison_sociale' => null]);
        $ordonnance = Ordonnance::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'clinique_id' => $soeur->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->getJson("/api/v1/ordonnances/{$ordonnance->id}", $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.en_tete.nom', 'Clinique du Lac');
        $this->getJson('/api/v1/ordonnances?patient_id='.$this->patient->id, $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.0.en_tete.nom', 'Clinique du Lac');
    }

    public function test_la_signature_cree_l_ordonnance_et_sort_une_unite_par_ligne(): void
    {
        $produit = $this->medicamentEnStock(9);
        $annee = aujourdhui()->year;

        $reponse = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'posologie' => '0,1 mg/kg — 1× par jour'],
            ],
            'renouvellements' => 1,
            'note' => 'Contrôle si la boiterie persiste.',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.numero', "RX-{$annee}-001")
            ->assertJsonPath('data.statut', 'active')
            ->assertJsonPath('data.renouvellements', 1)
            ->assertJsonPath('data.lignes.0.duree', 'Selon besoin');

        $this->assertSame(8, $this->stockDe($produit));

        $ordonnance = Ordonnance::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -1,
            'libelle' => 'Sortie : ordonnance '.$ordonnance->numero,
            'source_type' => $ordonnance->getMorphClass(),
            'source_id' => $ordonnance->id,
        ]);
    }

    public function test_la_signature_en_rupture_ne_bloque_pas_et_ne_trace_rien(): void
    {
        $produit = $this->medicamentEnStock(0);

        $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp matin et soir']],
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame(0, $this->stockDe($produit));
        $this->assertSame(0, MouvementStock::withoutGlobalScopes()->count());
    }

    public function test_seul_un_medicament_se_prescrit(): void
    {
        $materiel = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'categorie' => 'mat',
        ]);

        $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $materiel->id, 'posologie' => 'Sans objet']],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.0.produit_id');
    }

    public function test_l_annulation_dans_la_fenetre_supprime_et_restaure_le_stock(): void
    {
        $produit = $this->medicamentEnStock(5);

        $id = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp']],
        ], $this->enTetes($this->jeton))->json('data.id');

        $this->assertSame(4, $this->stockDe($produit));

        $this->deleteJson("/api/v1/ordonnances/{$id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('ordonnances', ['id' => $id]);
        $this->assertSame(5, $this->stockDe($produit));

        // L'historique reste complet : la sortie ET son inverse.
        $this->assertSame(
            [-1, 1],
            MouvementStock::withoutGlobalScopes()->orderBy('id')->pluck('quantite')->all(),
        );
    }

    public function test_l_annulation_hors_fenetre_est_refusee(): void
    {
        $produit = $this->medicamentEnStock(5);

        $id = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp']],
        ], $this->enTetes($this->jeton))->json('data.id');

        $this->travel(21)->seconds();

        $this->deleteJson("/api/v1/ordonnances/{$id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ordonnance' => 'Une ordonnance signée ne se supprime pas, clôturez-la. Seule l’annulation immédiate d’une signature est tolérée.']);

        $this->assertDatabaseHas('ordonnances', ['id' => $id]);
    }

    public function test_l_annulation_par_un_autre_signataire_est_refusee(): void
    {
        $produit = $this->medicamentEnStock(5);

        $id = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp']],
        ], $this->enTetes($this->jeton))->json('data.id');

        ['jeton' => $autreJeton] = $this->employeAvecJeton($this->clinique);

        $this->deleteJson("/api/v1/ordonnances/{$id}", [], $this->enTetes($autreJeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ordonnance' => 'Une ordonnance signée ne se supprime pas, clôturez-la. Seule l’annulation immédiate d’une signature est tolérée.']);
    }

    public function test_le_renouvellement_cree_une_nouvelle_ordonnance_et_clot_l_ancienne(): void
    {
        $produit = $this->medicamentEnStock(5);
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'renouvellements' => 2,
        ]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => $produit->id,
            'posologie' => '1 cp matin et soir',
        ]);

        $reponse = $this->postJson("/api/v1/ordonnances/{$ordonnance->id}/renouveler", [], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.renouvellements', 1)
            ->assertJsonPath('data.statut', 'active')
            ->assertJsonPath('data.ordonnance_precedente_id', $ordonnance->id)
            ->assertJsonPath('data.lignes.0.posologie', '1 cp matin et soir');

        $this->assertSame(StatutOrdonnance::Cloturee, $ordonnance->fresh()->statut);

        // Pas de nouvelle sortie de stock : la délivrance est l'affaire
        // de la boutique (parité front).
        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_le_renouvellement_sans_compteur_restant_est_refuse(): void
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'renouvellements' => 0,
        ]);

        $this->postJson("/api/v1/ordonnances/{$ordonnance->id}/renouveler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('renouvellements');
    }

    public function test_l_annulation_d_un_renouvellement_reactive_la_precedente(): void
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'renouvellements' => 1,
        ]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => $this->medicamentEnStock(5)->id,
        ]);

        $renouvellement = $this->postJson("/api/v1/ordonnances/{$ordonnance->id}/renouveler", [], $this->enTetes($this->jeton))
            ->json('data.id');

        $this->deleteJson("/api/v1/ordonnances/{$renouvellement}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('ordonnances', ['id' => $renouvellement]);
        $this->assertSame(StatutOrdonnance::Active, $ordonnance->fresh()->statut);
    }

    public function test_la_cloture_rend_l_ordonnance_non_delivrable(): void
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->postJson("/api/v1/ordonnances/{$ordonnance->id}/cloturer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'stop');

        $this->postJson("/api/v1/ordonnances/{$ordonnance->id}/cloturer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Cette ordonnance est déjà clôturée.']);
    }

    public function test_le_registre_filtre_par_statut_et_par_recherche(): void
    {
        $active = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'numero' => 'RX-2026-058',
        ]);
        Ordonnance::factory()->cloturee()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->getJson('/api/v1/ordonnances?statut=active', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/v1/ordonnances?q=058', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', 'RX-2026-058');
    }
}
