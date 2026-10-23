<?php

namespace Tests\Feature\Facturation;

use App\Enums\ModePrescription;
use App\Enums\MoyenPaiement;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\MouvementStock;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\EncaissementFacture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Encaissement (docs/data-model.md §6) : lignes ajoutées au prix du
 * catalogue ou du produit (médicament = prescription jointe, rupture =
 * chemin pharmacie), sortie de stock UNE seule fois — au paiement ou au
 * « payer plus tard » —, gestes bornés à la clinique d'émission,
 * décompte d'assurance affecté puis avancé.
 */
class EncaissementTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function factureDue(): Facture
    {
        return Facture::factory()->create(['clinique_id' => $this->clinique->id]);
    }

    private function medicamentEnStock(int $quantite, float $prix = 24.9): Produit
    {
        $produit = Produit::factory()->medicament()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'prix' => $prix,
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

    public function test_une_ligne_se_corrige_et_se_retire_tant_que_la_facture_est_due(): void
    {
        $facture = $this->factureDue();
        $acte = FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 50, 'quantite' => 1]);
        $autre = FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 10, 'quantite' => 1]);

        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$acte->id}", ['quantite' => 3], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.total_ttc', 160)
            ->assertJsonFragment(['id' => $acte->id, 'quantite' => 3]);

        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$acte->id}", ['quantite' => 0], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');

        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$acte->id}", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonPath('data.lignes.0.id', $autre->id)
            ->assertJsonPath('data.total_ttc', 10);

        $this->assertDatabaseMissing('facture_lignes', ['id' => $acte->id]);

        // La dernière ligne reste : une facture vide se supprime, elle ne s'encaisse pas.
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$autre->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ligne');
    }

    public function test_retirer_une_ligne_medicament_retire_sa_prescription_jointe(): void
    {
        $facture = $this->factureDue();
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        $produit = $this->medicamentEnStock(5);

        $reponse = $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", [
            'produit_id' => $produit->id,
            'posologie' => '1 cp matin et soir',
        ], $this->enTetes($this->jeton))->assertOk();

        $ligneMed = collect($reponse->json('data.lignes'))->firstWhere('produit_id', $produit->id)['id'];
        $this->assertCount(1, $reponse->json('data.prescriptions'));

        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligneMed}", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonCount(0, 'data.prescriptions');

        // Rien n'est sorti du stock : la sortie n'a lieu qu'au paiement.
        $this->assertSame(5, $this->stockDe($produit));
        $this->assertSame(0, MouvementStock::withoutGlobalScopes()->count());
    }

    public function test_une_ligne_medicament_au_stock_deja_sorti_est_figee(): void
    {
        $facture = $this->factureDue();
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        $produit = $this->medicamentEnStock(5);

        $reponse = $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $produit->id], $this->enTetes($this->jeton))->assertOk();
        $ligneMed = collect($reponse->json('data.lignes'))->firstWhere('produit_id', $produit->id)['id'];
        $ligneActe = collect($reponse->json('data.lignes'))->firstWhere('produit_id', null)['id'];

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/payer-plus-tard", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.stock_sorti', true);

        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligneMed}", ['quantite' => 2], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('ligne');
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligneMed}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('ligne');

        // Un acte, lui, se corrige encore : la facture reste due.
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligneActe}", ['quantite' => 2], $this->enTetes($this->jeton))
            ->assertOk();
        $this->assertSame(4, $this->stockDe($produit));
    }

    public function test_les_lignes_d_une_facture_payee_ou_d_une_autre_clinique_ne_se_corrigent_pas(): void
    {
        $payee = Facture::factory()->payee()->create(['clinique_id' => $this->clinique->id]);
        $lignePayee = FactureLigne::factory()->create(['facture_id' => $payee->id]);

        $this->patchJson("/api/v1/facturation/factures/{$payee->id}/lignes/{$lignePayee->id}", ['quantite' => 2], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('facture');
        $this->deleteJson("/api/v1/facturation/factures/{$payee->id}/lignes/{$lignePayee->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('facture');

        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $soeur = Facture::factory()->create(['clinique_id' => $cliniqueSoeur->id, 'organisation_id' => $this->clinique->societe->organisation_id]);
        $ligneSoeur = FactureLigne::factory()->create(['facture_id' => $soeur->id]);

        $this->patchJson("/api/v1/facturation/factures/{$soeur->id}/lignes/{$ligneSoeur->id}", ['quantite' => 2], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('facture');

        // Une ligne d'une autre facture est introuvable via celle-ci.
        $facture = $this->factureDue();
        FactureLigne::factory()->count(2)->create(['facture_id' => $facture->id]);
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$lignePayee->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->assertSame(1, $lignePayee->fresh()->quantite);
    }

    public function test_un_acte_s_ajoute_au_prix_du_catalogue(): void
    {
        $facture = $this->factureDue();
        $prestation = Prestation::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('code', 'detart')
            ->sole();

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", [
            'prestation_id' => $prestation->id,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.lignes.0.libelle', 'Détartrage + polissage')
            ->assertJsonPath('data.lignes.0.prix_chf', 260)
            ->assertJsonPath('data.total_ttc', 260);
    }

    public function test_un_medicament_en_stock_s_ajoute_avec_sa_prescription(): void
    {
        $facture = $this->factureDue();
        $produit = $this->medicamentEnStock(3);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", [
            'produit_id' => $produit->id,
            'posologie' => '1 cp matin et soir — 10 jours',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.lignes.0.type', 'med')
            ->assertJsonPath('data.lignes.0.prix_chf', 24.9)
            ->assertJsonPath('data.prescriptions.0.mode', 'stock')
            ->assertJsonPath('data.prescriptions.0.posologie', '1 cp matin et soir — 10 jours');

        // Le stock ne sort qu'au paiement.
        $this->assertSame(3, $this->stockDe($produit));
    }

    public function test_un_medicament_en_rupture_est_refuse_vers_le_chemin_pharmacie(): void
    {
        $facture = $this->factureDue();
        $produit = $this->medicamentEnStock(0);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", [
            'produit_id' => $produit->id,
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('produit_id');

        // Le chemin pharmacie : prescription jointe, aucune ligne.
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/prescriptions", [
            'produit_id' => $produit->id,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.prescriptions.0.mode', ModePrescription::Pharmacie->value)
            ->assertJsonCount(0, 'data.lignes');
    }

    public function test_l_encaissement_marque_payee_et_sort_le_stock_une_seule_fois(): void
    {
        $facture = $this->factureDue();
        $produit = $this->medicamentEnStock(5);
        FactureLigne::factory()->create([
            'facture_id' => $facture->id,
            'libelle' => $produit->nom,
            'type' => 'med',
            'produit_id' => $produit->id,
            'prix_chf' => 24.9,
        ]);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", [
            'moyen_paiement' => 'tpe',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'payee')
            ->assertJsonPath('data.moyen_paiement', 'tpe')
            ->assertJsonPath('data.stock_sorti', true);

        $this->assertSame(4, $this->stockDe($produit));
        $this->assertNotNull($facture->fresh()->payee_le);
        $this->assertDatabaseHas('mouvements_stock', [
            'produit_id' => $produit->id,
            'quantite' => -1,
            'source_type' => $facture->getMorphClass(),
            'source_id' => $facture->id,
        ]);

        // Une facture payée ne s'encaisse pas deux fois.
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", [
            'moyen_paiement' => 'cash',
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors(['facture' => 'Cette facture est déjà payée.']);
    }

    public function test_un_encaissement_parti_d_une_facture_perimee_ne_double_ni_le_paiement_ni_le_stock(): void
    {
        $facture = $this->factureDue();
        $produit = $this->medicamentEnStock(5);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => $produit->nom, 'type' => 'med', 'produit_id' => $produit->id, 'prix_chf' => 24.9]);
        // Lue par une seconde caisse avant que la première encaisse
        $perimee = Facture::withoutGlobalScopes()->findOrFail($facture->id);

        ['user' => $caissier, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'cash'], $this->enTetes($jeton))->assertOk();
        $this->assertSame($caissier->id, $facture->fresh()->encaissee_par_id);

        try {
            app(EncaissementFacture::class)->encaisser($perimee, MoyenPaiement::Tpe, $caissier);
            $this->fail('Une facture payée a été encaissée une seconde fois.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('facture', $e->errors());
        }

        $this->assertSame(4, $this->stockDe($produit));
        $this->assertSame('cash', $facture->fresh()->moyen_paiement->value);
    }

    public function test_une_facture_manuelle_prend_le_prix_du_produit_et_sort_le_stock_a_l_encaissement(): void
    {
        $produit = $this->medicamentEnStock(5, 24.9);

        $facture = $this->postJson('/api/v1/facturation/factures', [
            'patient_name' => 'Rex',
            'stock_sorti' => true,
            'lignes' => [
                ['libelle' => 'Antibiotique', 'prix_chf' => 1, 'type' => 'acte', 'produit_id' => $produit->id],
                ['libelle' => 'Geste commercial', 'prix_chf' => -5, 'type' => 'acte'],
            ],
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.stock_sorti', false)
            ->json('data');

        $this->assertEquals(24.9, (float) Facture::withoutGlobalScopes()->findOrFail($facture['id'])->lignes()->where('produit_id', $produit->id)->value('prix_chf'));
        $this->assertSame('med', Facture::withoutGlobalScopes()->findOrFail($facture['id'])->lignes()->where('produit_id', $produit->id)->value('type')->value);

        $this->postJson("/api/v1/facturation/factures/{$facture['id']}/encaisser", ['moyen_paiement' => 'cash'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(4, $this->stockDe($produit));
    }

    public function test_payer_plus_tard_sort_le_stock_et_laisse_la_facture_due(): void
    {
        $facture = $this->factureDue();
        $produit = $this->medicamentEnStock(5);
        FactureLigne::factory()->create([
            'facture_id' => $facture->id,
            'libelle' => $produit->nom,
            'type' => 'med',
            'produit_id' => $produit->id,
        ]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/payer-plus-tard", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'due')
            ->assertJsonPath('data.stock_sorti', true);

        $this->assertSame(4, $this->stockDe($produit));

        // L'encaissement ultérieur ne décrémente pas une seconde fois.
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'twint'], $entetes)->assertOk();

        $this->assertSame(4, $this->stockDe($produit));
        $this->assertSame(1, MouvementStock::withoutGlobalScopes()->count());
    }

    public function test_l_encaissement_en_especes_verifie_le_montant_recu(): void
    {
        $facture = $this->factureDue();
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 85]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", [
            'moyen_paiement' => 'cash',
            'montant_recu' => 50,
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('montant_recu');

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", [
            'moyen_paiement' => 'cash',
            'montant_recu' => 100,
        ], $entetes)->assertOk();
    }

    public function test_les_gestes_de_caisse_se_font_depuis_la_clinique_d_emission(): void
    {
        $facture = $this->factureDue();

        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($cliniqueSoeur);

        // La facture reste lisible de toute l'organisation…
        $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($jetonSoeur))->assertOk();

        // …mais son tiroir est celui de la clinique d'émission.
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", [
            'moyen_paiement' => 'tpe',
        ], $this->enTetes($jetonSoeur))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('facture');
    }

    public function test_le_decompte_d_assurance_s_affecte_puis_avance_jusqu_au_remboursement(): void
    {
        $facture = $this->factureDue();
        $entetes = $this->enTetes($this->jeton);

        $this->putJson("/api/v1/facturation/factures/{$facture->id}/decompte", [
            'assureur' => 'Animalia',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.assureur', 'Animalia')
            ->assertJsonPath('data.statut', 'todo');

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/decompte/avancer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'sent');

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/decompte/avancer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'paid');

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/decompte/avancer", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decompte' => 'Le remboursement est déjà encaissé.']);

        $decompte = $facture->fresh()->decompte;
        $this->assertNotNull($decompte->envoye_le);
        $this->assertNotNull($decompte->rembourse_le);
    }

    public function test_avancer_sans_assureur_affecte_est_refuse(): void
    {
        $facture = $this->factureDue();

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/decompte/avancer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decompte');
    }
}
