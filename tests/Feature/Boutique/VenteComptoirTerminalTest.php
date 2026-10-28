<?php

namespace Tests\Feature\Boutique;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\Paiements\PaiementsCaisse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vente comptoir par le terminal et TWINT (décision de Jim, 28.10.2026) :
 * en paiement intégré, une vente par carte ou TWINT attend le terminal ou
 * le QR TWINT, comme une facture ; le stock ne sort qu'au paiement réussi.
 */
class VenteComptoirTerminalTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function produitEnRayon(int $quantite): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true, 'est_sur_ordonnance' => false, 'prix' => 30, 'promo_pct' => 0,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $quantite]);

        return $produit;
    }

    private function stockDe(Produit $produit): int
    {
        return Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite;
    }

    private function vendre(Produit $produit, string $moyen): TestResponse
    {
        return $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => $moyen,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ], $this->enTetes($this->jeton));
    }

    public function test_en_paiement_integre_une_vente_par_carte_attend_le_terminal(): void
    {
        $this->assertTrue($this->clinique->encaisseAvecVeterinary());
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'tpe')->assertCreated()
            ->assertJsonPath('data.statut', 'due')
            ->assertJsonPath('data.payee_le', null)
            ->assertJsonPath('data.total_ttc', 60);

        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_en_paiement_integre_une_vente_par_twint_attend_le_qr_twint(): void
    {
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'twint')->assertCreated()->assertJsonPath('data.statut', 'due');

        $this->assertSame(5, $this->stockDe($produit));
        // Pas encore payée : hors des chiffres de la boutique
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.totaux.tickets', 0);
    }

    public function test_le_paiement_reussi_au_terminal_paie_la_vente_sort_le_stock_et_le_z_ne_la_signale_pas(): void
    {
        $produit = $this->produitEnRayon(5);
        $facture = Facture::withoutGlobalScopes()->findOrFail($this->vendre($produit, 'tpe')->json('data.id'));
        $this->assertSame('due', $facture->statut->value);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Tpe,
            'montant' => 60, 'stripe_payment_intent_id' => 'pi_vente', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->appliquer($paiement, 'succeeded', null);

        $this->assertSame('payee', $facture->refresh()->statut->value);
        $this->assertSame(3, $this->stockDe($produit));
        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.moyens.tpe.nombre', 1)
            ->assertJsonPath('data.moyens.tpe.sans_terminal', 0);
    }

    public function test_en_especes_la_vente_reste_encaissee_tout_de_suite(): void
    {
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'cash')->assertCreated()->assertJsonPath('data.statut', 'payee');

        $this->assertSame(3, $this->stockDe($produit));
    }

    public function test_sans_paiement_integre_une_carte_est_encaissee_tout_de_suite(): void
    {
        $this->clinique->update(['paiements_integres' => false]);
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'tpe')->assertCreated()->assertJsonPath('data.statut', 'payee');

        $this->assertSame(3, $this->stockDe($produit));
    }
}
