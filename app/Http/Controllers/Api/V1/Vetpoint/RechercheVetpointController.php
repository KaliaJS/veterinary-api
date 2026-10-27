<?php

namespace App\Http\Controllers\Api\V1\Vetpoint;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\ConnexionVetpoint;
use App\Models\Produit;
use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointCgv;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recherche Vetpoint (Stock, 27.10.2026) : par nom ou numéro Swissmedic,
 * avec la clé de la clinique du jeton. Les 20 premiers résultats, chacun
 * au prix de la clinique pour une pièce (un seul calcul de prix), avec le
 * produit du catalogue qui lui est relié. Droit « Stocks ».
 */
class RechercheVetpointController extends Controller
{
    private const int RESULTATS_MAX = 20;

    private const int RECHERCHES_PAR_MINUTE = 20;

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

        // Vetpoint accepte 50 appels par minute et par clé, une recherche en
        // fait 2 : 20 recherches par minute pour la clinique, toutes personnes
        // confondues. Seules les vraies recherches comptent (une requête
        // refusée plus haut ne bloque pas les collègues)
        // Clé illisible (clé de chiffrement du serveur changée) : à recoller
        $cle = $connexion->cleEnClair();
        abort_if($cle === null, 409, 'La clé Vetpoint de la clinique est illisible : collez-la à nouveau dans Réglages, Fournisseurs.');

        // Compté d'un coup (incrément atomique) : des recherches simultanées
        // ne passent pas toutes à la 20e
        $compteur = 'vetpoint-recherches:'.$connexion->clinique_id;
        abort_if(RateLimiter::hit($compteur, 60) > self::RECHERCHES_PAR_MINUTE, 429, 'Trop de recherches Vetpoint pour la clinique : réessayez dans une minute.');

        $client = ClientVetpoint::pour($cle);

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
        } catch (VetpointCgv) {
            abort(409, 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans le profil Vetpoint de la clinique, puis réessayez.');
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
                // Un champ de forme inattendue (objet au lieu de texte) : vide
                $texte = fn (string $champ): ?string => isset($produit[$champ]) && is_scalar($produit[$champ]) ? (string) $produit[$champ] : null;

                return [
                    'id' => $id,
                    'nom' => $texte('name') ?? '',
                    'swissmedic' => $texte('swissmedicNumber'),
                    'fournisseur' => $fournisseurs[$texte('supplierId') ?? ''] ?? null,
                    'disponible' => (bool) ($produit['available'] ?? false),
                    'peremption' => $texte('expirationDate'),
                    'categorie_remise' => $texte('abgabekategorie'),
                    // Vetpoint met parfois 0, sans unité : rien à montrer
                    'contenu' => (ClientVetpoint::nombre($produit['anzahl'] ?? null) ?? 0) > 0 ? trim($produit['anzahl'].' '.($texte('einheitVerkauf') ?? '')) : null,
                    'stupefiant' => (bool) ($produit['betaeubungsmittel'] ?? false),
                    'ean' => $texte('barcode'),
                    'prix_catalogue' => ClientVetpoint::nombre($produit['listPrice'] ?? null),
                    'prix_clinique' => $prix[$id] ?? null,
                    'produit' => $relie ? ['id' => $relie->id, 'nom' => $relie->nom] : null,
                ];
            }, $premiers),
            'total' => count($produits),
        ]);
    }
}
