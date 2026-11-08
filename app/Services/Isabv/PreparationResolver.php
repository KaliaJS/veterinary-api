<?php

namespace App\Services\Isabv;

use App\Models\IsabvPreparation;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Services\Catalogue\AtcFromCatalogue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * La préparation SI ABV d'un article du stock (08.11.2026) : celle choisie à
 * la main sur l'article, sinon celle de son code-barres (emballage Swissmedic
 * du catalogue : n° d'autorisation et séquence, le code de l'OSAV). Un
 * article est un antibiotique à déclarer si sa préparation est marquée
 * « transfert SI ABV » dans la liste de l'OSAV ; rien n'est deviné d'après
 * le nom ni le code ATC.
 */
class PreparationResolver
{
    /**
     * @param  iterable<Produit>  $products
     * @return array<int, IsabvPreparation> id de l'article => préparation
     */
    public function forProducts(iterable $products): array
    {
        $products = collect($products);
        $codes = [];
        $byGtin = $this->codesByGtin($products->pluck('ean'));
        foreach ($products as $product) {
            $manual = trim((string) $product->isabv_preparation_code);
            $codes[$product->id] = $manual !== '' ? [$manual] : ($byGtin[AtcFromCatalogue::clean($product->ean)] ?? []);
        }
        $preparations = IsabvPreparation::query()->whereIn('code', array_unique(array_merge(...array_values($codes ?: [[]]))))->get()->keyBy('code');
        $found = [];
        foreach ($codes as $productId => $candidates) {
            foreach ($candidates as $code) {
                if ($preparations->has($code)) {
                    $found[$productId] = $preparations->get($code);
                    break;
                }
            }
        }

        return $found;
    }

    public function forProduct(Produit $product): ?IsabvPreparation
    {
        return $this->forProducts([$product])[$product->id] ?? null;
    }

    /** La préparation, si elle est à déclarer */
    public function declarable(Produit $product): ?IsabvPreparation
    {
        $preparation = $this->forProduct($product);

        return $preparation?->must_transfer ? $preparation : null;
    }

    /**
     * Les codes-barres du catalogue dont la préparation est à déclarer (une
     * heure en cache) : la vitrine publique ne les montre pas.
     *
     * @return list<string>
     */
    public function declarableGtins(): array
    {
        return Cache::remember('isabv:declarable-gtins', 3600, function (): array {
            $declarable = IsabvPreparation::query()->where('must_transfer', true)->pluck('code')->flip();
            $gtins = [];
            ProduitEmballage::query()->whereNotNull('gtin')
                ->join('produits_veterinaires', 'produits_veterinaires.id', '=', 'produit_emballages.produit_id')
                ->toBase()->get(['produit_emballages.gtin', 'produit_emballages.sequence', 'produits_veterinaires.numero_autorisation'])
                ->each(function ($row) use ($declarable, &$gtins): void {
                    $code = IsabvPreparation::swissCode((string) $row->numero_autorisation, (string) $row->sequence);
                    if ($code !== null && isset($declarable[$code])) {
                        $gtins[] = (string) $row->gtin;
                    }
                });

            return array_values(array_unique($gtins));
        });
    }

    /**
     * Les codes possibles d'un code-barres : n° d'autorisation sur 5 chiffres
     * (« 01084-01 »), puis tel qu'au catalogue si l'OSAV ne complète pas.
     *
     * @param  Collection<int, mixed>  $gtins
     * @return array<string, list<string>>
     */
    private function codesByGtin(Collection $gtins): array
    {
        $clean = $gtins->map(fn ($gtin) => AtcFromCatalogue::clean($gtin))->filter()->unique()->values();
        if ($clean->isEmpty()) {
            return [];
        }
        $codes = [];
        ProduitEmballage::query()
            ->whereIn('gtin', $clean->all())
            ->join('produits_veterinaires', 'produits_veterinaires.id', '=', 'produit_emballages.produit_id')
            ->toBase()
            ->get(['produit_emballages.gtin', 'produit_emballages.sequence', 'produits_veterinaires.numero_autorisation'])
            ->each(function ($row) use (&$codes): void {
                $padded = IsabvPreparation::swissCode((string) $row->numero_autorisation, (string) $row->sequence);
                if ($padded !== null) {
                    $raw = ltrim(trim((string) $row->numero_autorisation), '0').'-'.str_pad(trim((string) $row->sequence), 2, '0', STR_PAD_LEFT);
                    $codes[(string) $row->gtin] = array_values(array_unique([$padded, $raw]));
                }
            });

        return $codes;
    }
}
