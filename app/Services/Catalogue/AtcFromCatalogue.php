<?php

namespace App\Services\Catalogue;

use App\Models\ProduitEmballage;
use Illuminate\Support\Facades\DB;

/**
 * Le code ATC d'un article du stock, repris du catalogue Swissmedic par le
 * code-barres de son emballage (GTIN Refdata) ; null si le code-barres n'y
 * est pas (03.11.2026).
 */
final class AtcFromCatalogue
{
    public static function forGtin(?string $gtin): ?string
    {
        $clean = preg_replace('/\s+/', '', (string) $gtin);
        if ($clean === '' || $clean === null) {
            return null;
        }

        $code = ProduitEmballage::query()
            ->where('gtin', $clean)
            ->join('produits_veterinaires', 'produits_veterinaires.id', '=', 'produit_emballages.produit_id')
            ->value('produits_veterinaires.atc_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Les principes actifs du catalogue (noms latins, tels quels) pour des
     * codes-barres, en une requête : GTIN nettoyé → noms. Un code-barres
     * absent du catalogue n'a pas d'entrée.
     *
     * @param  iterable<string|null>  $gtins
     * @return array<string, list<string>>
     */
    public static function substancesForGtins(iterable $gtins): array
    {
        $clean = collect($gtins)->map(fn ($g): string => (string) preg_replace('/\s+/', '', (string) $g))->filter()->unique()->values();
        if ($clean->isEmpty()) {
            return [];
        }

        $byGtin = [];
        $rows = DB::table('produit_emballages')
            ->whereIn('produit_emballages.gtin', $clean)
            ->join('produits_veterinaires', 'produits_veterinaires.id', '=', 'produit_emballages.produit_id')
            ->get(['produit_emballages.gtin', 'produits_veterinaires.substances']);
        foreach ($rows as $row) {
            $names = json_decode((string) $row->substances, true);
            $byGtin[(string) $row->gtin] = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
        }

        return $byGtin;
    }

    public static function clean(?string $gtin): string
    {
        return (string) preg_replace('/\s+/', '', (string) $gtin);
    }
}
