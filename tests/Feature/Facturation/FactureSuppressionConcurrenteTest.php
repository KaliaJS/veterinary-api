<?php

namespace Tests\Feature\Facturation;

use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use App\Services\GestionnaireStock;
use Database\Factories\PaiementFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Deux suppressions de la même facture en même temps (décision de Jim,
 * 07.11.2026) : le stock des articles vendus ne revient qu'une fois.
 */
class FactureSuppressionConcurrenteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_deux_suppressions_simultanees_ne_remettent_le_stock_qu_une_fois(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton, 'user' => $user] = $this->employeAvecJeton($clinique);
        $produit = Produit::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'est_sur_ordonnance' => false, 'est_stupefiant' => false]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $clinique->id, 'quantite' => 7]));

        $id = $this->postJson('/api/v1/facturation/factures', ['patient_name' => 'Rex', 'lignes' => [['libelle' => 'Article', 'prix_chf' => 1, 'type' => 'acte', 'produit_id' => $produit->id, 'quantite' => 2]]], $this->enTetes($jeton))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/facturation/factures/{$id}/payer-plus-tard", [], $this->enTetes($jeton))->assertOk();
        $this->assertSame(5, $this->stockDe($produit, $clinique));

        // L'autre suppression passe pile pendant les contrôles de celle-ci
        $fait = false;
        DB::listen(function ($requete) use ($id, $user, &$fait): void {
            if ($fait || ! str_contains($requete->sql, 'from "sejours"')) {
                return;
            }
            $fait = true;
            $facture = Facture::withoutGlobalScopes()->findOrFail($id);
            DB::transaction(function () use ($facture, $user): void {
                app(GestionnaireStock::class)->inverserMouvementsDe($facture, 'Annulation facture '.$facture->numero, User::findOrFail($user->id));
                $facture->delete();
            });
        });

        $this->deleteJson("/api/v1/facturation/factures/{$id}", [], $this->enTetes($jeton))->assertNoContent();

        $this->assertTrue($fait);
        $this->assertSame(7, $this->stockDe($produit, $clinique));
    }

    private function stockDe(Produit $produit, Clinique $clinique): int
    {
        return (int) Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $clinique->id)->value('quantite');
    }

    /**
     * Jim (07.11.2026) : les contrôles se refont sous le verrou. Un paiement
     * par terminal démarre pile pendant la suppression : la facture reste.
     */
    public function test_un_paiement_qui_demarre_pendant_la_suppression_garde_la_facture(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $id = $this->postJson('/api/v1/facturation/factures', ['patient_name' => 'Rex', 'lignes' => [['libelle' => 'Consultation', 'prix_chf' => 80, 'type' => 'acte']]], $this->enTetes($jeton))->assertCreated()->json('data.id');

        // Le paiement démarre juste après les contrôles faits hors verrou
        $fait = false;
        DB::listen(function ($requete) use ($id, &$fait): void {
            if ($fait || ! str_contains($requete->sql, 'from "decomptes_assurance"')) {
                return;
            }
            $fait = true;
            PaiementFactory::new()->create(['facture_id' => $id]);
        });

        $this->deleteJson("/api/v1/facturation/factures/{$id}", [], $this->enTetes($jeton))->assertUnprocessable();

        $this->assertTrue($fait);
        $this->assertTrue(Facture::withoutGlobalScopes()->whereKey($id)->exists());
    }
}
