<?php

namespace App\Http\Controllers\Api\V1\Vetpoint;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\ConnexionVetpoint;
use App\Models\Produit;
use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Recherche Vetpoint (Stock, 27.10.2026) : par nom ou numéro Swissmedic,
 * avec la clé de la clinique du jeton. Les 20 premiers résultats, chacun
 * au prix de la clinique pour une pièce (un seul calcul de prix), avec le
 * produit du catalogue qui lui est relié. Droit « Stocks ».
 */
class RechercheVetpointController extends Controller
{
    private const int RESULTATS_MAX = 20;

    public function __invoke(Request $request, ContexteClinique $contexte): JsonResponse
    {
        $texte = trim((string) $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'q.required' => 'Tapez un nom ou un numéro Swissmedic.',
            'q.min' => 'Tapez au moins 2 lettres.',
        ])['q']);

        $connexion = ConnexionVetpoint::query()->where('clinique_id', $contexte->cliniqueId())->first();
        abort_if($connexion === null, 409, 'Vetpoint n’est pas relié à cette clinique : collez sa clé dans Réglages, Fournisseurs.');
        $client = ClientVetpoint::pour($connexion->cle);

        try {
            $produits = $client->rechercher($texte);
            $premiers = array_slice($produits, 0, self::RESULTATS_MAX);
            $prix = $premiers === [] ? [] : $client->prixUnitaires(array_map(fn (array $produit): string => (string) $produit['productId'], $premiers));
            $fournisseurs = $premiers === [] ? [] : Cache::remember(
                ConnexionVetpointController::cleCacheFournisseurs($connexion->clinique_id),
                now()->addDay(),
                fn (): array => $client->fournisseurs(),
            );
        } catch (VetpointCleRefusee) {
            abort(409, 'Vetpoint refuse la clé de la clinique : elle a peut-être été renouvelée. Générez-en une et collez-la dans Réglages, Fournisseurs.');
        } catch (VetpointLimite) {
            abort(429, 'Vetpoint limite les recherches : réessayez dans une minute.');
        } catch (VetpointIndisponible) {
            abort(503, 'Vetpoint ne répond pas : réessayez dans un moment.');
        }

        // Produits du catalogue de l'organisation déjà reliés à ces résultats
        $relies = Produit::query()
            ->whereHas('vetpoint', fn ($lien) => $lien->whereIn('vetpoint_id', array_map(fn (array $produit): string => (string) $produit['productId'], $premiers)))
            ->with('vetpoint')
            ->get()
            ->keyBy(fn (Produit $produit): string => (string) $produit->vetpoint?->vetpoint_id);

        return response()->json([
            'data' => array_map(function (array $produit) use ($prix, $fournisseurs, $relies): array {
                $id = (string) $produit['productId'];
                $relie = $relies->get($id);

                return [
                    'id' => $id,
                    'nom' => (string) ($produit['name'] ?? ''),
                    'swissmedic' => $produit['swissmedicNumber'] ?? null,
                    'fournisseur' => $fournisseurs[(string) ($produit['supplierId'] ?? '')] ?? null,
                    'disponible' => (bool) ($produit['available'] ?? false),
                    'peremption' => $produit['expirationDate'] ?? null,
                    'categorie_remise' => $produit['abgabekategorie'] ?? null,
                    // Vetpoint met parfois 0, sans unité : rien à montrer
                    'contenu' => (float) ($produit['anzahl'] ?? 0) > 0 ? trim($produit['anzahl'].' '.($produit['einheitVerkauf'] ?? '')) : null,
                    'stupefiant' => (bool) ($produit['betaeubungsmittel'] ?? false),
                    'ean' => $produit['barcode'] ?? null,
                    'prix_catalogue' => isset($produit['listPrice']) ? (float) $produit['listPrice'] : null,
                    'prix_clinique' => $prix[$id] ?? null,
                    'produit' => $relie ? ['id' => $relie->id, 'nom' => $relie->nom] : null,
                ];
            }, $premiers),
            'total' => count($produits),
        ]);
    }
}
