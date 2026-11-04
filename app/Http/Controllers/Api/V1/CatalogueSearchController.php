<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProduitEmballage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La recherche générale du catalogue Swissmedic (nouvel article B,
 * 03.11.2026) : par code-barres (GTIN Refdata) ou par nom, chaque emballage
 * autorisé avec ce que l'article en reprend (nom, code ATC, ordonnance,
 * contenance). Toujours là, avec ou sans Vetpoint ; un code-barres inconnu
 * ne trouve rien, sans erreur. Données de plateforme : aucun contexte.
 */
class CatalogueSearchController extends Controller
{
    private const int MAX = 20;

    /** Les unités d'emballage de Swissmedic que l'app sait sortir à la dose */
    private const array DOSE_UNITS = [
        'ml' => 'ml', 'l' => 'l', 'mg' => 'mg', 'g' => 'g',
        'tablette(n)' => 'cp', 'tablette' => 'cp', 'tabletten' => 'cp', 'kapsel(n)' => 'caps', 'kapseln' => 'caps',
        'dose(n)' => 'dose', 'dosis' => 'dose', 'pipette(n)' => 'pipette', 'stück' => 'pièce',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'q.required' => 'Tapez un nom ou scannez un code-barres.',
            'q.min' => 'Tapez au moins 2 lettres.',
        ])['q']);
        $digits = preg_replace('/\s+/', '', $q);
        $isBarcode = preg_match('/^\d{8,14}$/', (string) $digits) === 1;
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';

        $packages = ProduitEmballage::query()
            ->with('produit')
            ->whereNull('retire_le')
            ->whereHas('produit', fn (Builder $produit) => $produit->whereNull('retire_le'))
            ->when($isBarcode, fn (Builder $query) => $query->where('gtin', $digits))
            ->when(! $isBarcode, fn (Builder $query) => $query->where(fn (Builder $or) => $or
                ->whereRaw("lower(coalesce(nom_fr, '')) like ? escape '!'", [$like])
                ->orWhereHas('produit', fn (Builder $produit) => $produit
                    ->whereRaw("lower(nom_court) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(nom) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(coalesce(atc_code, '')) like ? escape '!'", [$like]))))
            ->orderByRaw('gtin is null')
            ->orderBy('nom_fr')
            ->limit(self::MAX)
            ->get();

        return response()->json(['data' => $packages->map(function (ProduitEmballage $package): array {
            $produit = $package->produit;
            $unit = self::DOSE_UNITS[mb_strtolower(trim((string) $package->unite))] ?? null;
            $content = is_numeric(str_replace(',', '.', (string) $package->taille)) ? (float) str_replace(',', '.', (string) $package->taille) : null;

            return [
                'gtin' => $package->gtin,
                'name' => $package->nom_fr ?: trim($produit->nom_court.' '.$package->taille.' '.$package->unite),
                'pack' => trim($package->taille.' '.$package->unite),
                'swissmedic' => $produit->numero_autorisation.($package->numero_emballage ? '-'.$package->numero_emballage : ''),
                'holder' => $produit->titulaire,
                'atc_code' => $produit->atc_code,
                // Catégories de remise A et B : sur ordonnance
                'is_prescription' => in_array($produit->categorie_remise, ['A', 'B'], true),
                // Un antiparasitaire (ATCvet QP) dans sa famille, le reste parmi les médicaments
                'category' => str_starts_with((string) $produit->atc_code, 'QP') ? 'apara' : 'med',
                'content' => $unit !== null && $content !== null && $content > 0 ? $content : null,
                'dose_unit' => $unit !== null && $content !== null && $content > 0 ? $unit : null,
                'substances' => $produit->substances ?? [],
            ];
        })->values()]);
    }
}
