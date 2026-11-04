<?php

namespace Tests\Concerns;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\ProductOrderSetting;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\Supplier;

/**
 * Ce que partagent les essais de la commande automatique : des fournisseurs
 * de la clinique, des articles réglés « automatique » dans cette clinique,
 * une sortie de stock par l'API, le passage planifié.
 *
 * @property Clinique $clinique
 * @property string $jeton
 */
trait CommandeAutomatique
{
    private function fournisseur(string $nom, array $attributs = []): Supplier
    {
        return Supplier::query()->withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'name' => $nom, 'channel' => 'manual', ...$attributs]);
    }

    /** @param  list<Supplier>  $sources */
    private function article(string $nom, int $stock, int $seuil, array $sources, array $attributs = [], bool $isAuto = true, ?int $upTo = null): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => $nom, 'seuil_alerte' => $seuil, 'cout' => 10, 'est_stupefiant' => false,
            ...$attributs,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $stock]);
        ProductOrderSetting::query()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'is_auto_order' => $isAuto, 'reorder_up_to' => $upTo ?? $seuil * 2]);
        foreach ($sources as $rang => $source) {
            $produit->sources()->create(['supplier_id' => $source->id, 'rank' => $rang + 1, 'reference' => 'REF-'.$source->id, 'cost' => 20 + $rang]);
        }

        return $produit;
    }

    private function sortir(Produit $produit, int $quantite): void
    {
        $this->postJson('/api/v1/stocks/produits/'.$produit->id.'/ajuster', ['quantite' => -$quantite, 'motif' => 'usage'], $this->enTetes($this->jeton))->assertOk();
    }

    private function entrer(Produit $produit, int $quantite): void
    {
        $this->postJson('/api/v1/stocks/produits/'.$produit->id.'/ajuster', ['quantite' => $quantite, 'motif' => 'recep'], $this->enTetes($this->jeton))->assertOk();
    }

    private function panier(Supplier $fournisseur): ?CommandeFournisseur
    {
        return CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $fournisseur->id)->where('is_auto', true)->with('lignes')->latest('id')->first();
    }

    private function passe(): void
    {
        $this->artisan('orders:auto-send')->assertSuccessful();
    }

    /** Un passage, puis un autre une fois l'avis de 12 heures écoulé */
    private function passeJusquAuDepart(): void
    {
        $this->passe();
        $this->travel(12 * 60 + 5)->minutes();
        $this->passe();
    }

    private function setting(Produit $produit): ?ProductOrderSetting
    {
        return ProductOrderSetting::query()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->first();
    }
}
