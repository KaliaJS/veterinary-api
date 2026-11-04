<?php

namespace App\Http\Controllers\Api\V1\Stocks;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Lot;
use App\Models\Produit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * « À surveiller » des Stocks (vérification du 04.11.2026) : une seule règle,
 * servie, que l'écran lit sans la recalculer. Pour chaque article de
 * l'organisation, par clinique et toutes cliniques confondues :
 *
 * - la plus proche péremption des lots qui couvrent le stock actuel, au
 *   format « MM.YYYY ». Les lots ne sont pas décomptés à la vente ni à un
 *   ajustement : on suppose vendus les plus anciens. Rangés du plus
 *   lointain au plus proche, on garde ceux dont la somme couvre la
 *   quantité en stock ; un stock à zéro ne garde aucun lot ;
 *   et son état : `perime` (mois passé), `proche` (moins de 3 mois, la règle
 *   des lots « < 3 mois »), ou null ;
 * - le stock bas (seuil > 0 et stock au plus au seuil, comme
 *   Produit::estSousLeSeuilDans) ou la rupture (stock à zéro) : dans une
 *   clinique qui tient l'article ; toutes cliniques, sur le stock total
 *   (un article sans aucune ligne de stock y est en rupture, comme sa
 *   pastille) ;
 *
 * puis les comptes `a_surveiller` par clinique et `toutes`. La cloche des
 * alertes (2 mois) garde sa propre règle.
 */
class StockWatchController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Produit::class);

        $organisationId = app(ContexteClinique::class)->organisationId();
        $cliniqueIds = Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($societe) => $societe->withoutGlobalScopes()->where('organisation_id', $organisationId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $produits = Produit::query()
            ->with(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique')->whereIn('clinique_id', $cliniqueIds)])
            ->get(['id', 'seuil_alerte']);
        $lots = Lot::withoutGlobalScope('clinique')
            ->whereIn('produit_id', $produits->pluck('id'))
            ->whereIn('clinique_id', $cliniqueIds)
            ->where('quantite', '>', 0)
            ->get(['produit_id', 'clinique_id', 'peremption_le', 'quantite'])
            ->groupBy('produit_id');

        $debutMois = aujourdhui()->startOfMonth();
        $etatDe = fn (Carbon $date): ?string => $date->lt($debutMois) ? 'perime' : ($date->lt($debutMois->copy()->addMonths(3)) ? 'proche' : null);
        $peremption = fn ($dates): ?array => ($plusProche = $dates->min()) === null ? null : ['date' => $plusProche->format('m.Y'), 'etat' => $etatDe($plusProche)];
        $estBas = fn (int $seuil, int $quantite): bool => $quantite === 0 || ($seuil > 0 && $quantite <= $seuil);
        // Les lots qui couvrent un stock : du plus lointain au plus proche, jusqu'à couvrir la quantité.
        // Un lot sans péremption (matériel, accessoires) ne périme jamais : il passe en premier, sans date
        $couvrant = function ($lotsClinique, int $stock) {
            $gardes = collect();
            $somme = 0;
            foreach ($lotsClinique->sortByDesc(fn (Lot $lot) => $lot->peremption_le->timestamp ?? PHP_INT_MAX) as $lot) {
                if ($somme >= $stock) {
                    break;
                }
                $gardes->push($lot);
                $somme += (int) $lot->quantite;
            }

            return $gardes;
        };

        $comptes = ['toutes' => ['stock_bas' => 0, 'peremption_3_mois' => 0]];
        foreach ($cliniqueIds as $id) {
            $comptes[(string) $id] = ['stock_bas' => 0, 'peremption_3_mois' => 0];
        }

        $lignes = $produits->map(function (Produit $produit) use ($lots, $cliniqueIds, $peremption, $estBas, $couvrant, &$comptes): array {
            $seuil = (int) $produit->seuil_alerte;
            $sesLots = $lots->get($produit->id, collect());
            $parClinique = [];
            $bas = ['toutes' => $estBas($seuil, (int) $produit->stocks->sum('quantite'))];
            $tousCouvrants = collect();
            foreach ($cliniqueIds as $id) {
                $stock = $produit->stocks->firstWhere('clinique_id', $id);
                $gardes = $couvrant($sesLots->where('clinique_id', $id), (int) ($stock->quantite ?? 0));
                $tousCouvrants = $tousCouvrants->merge($gardes);
                $parClinique[(string) $id] = $peremption($gardes->pluck('peremption_le'));
                $bas[(string) $id] = $stock !== null && $estBas($seuil, (int) $stock->quantite);
            }
            $parClinique = ['toutes' => $peremption($tousCouvrants->pluck('peremption_le'))] + $parClinique;
            foreach ($parClinique as $portee => $valeur) {
                $comptes[$portee]['peremption_3_mois'] += $valeur !== null && $valeur['etat'] !== null ? 1 : 0;
                $comptes[$portee]['stock_bas'] += $bas[$portee] ? 1 : 0;
            }

            return ['id' => $produit->id, 'peremption' => $parClinique, 'stock_bas' => $bas];
        });

        return response()->json(['data' => ['produits' => $lignes->values(), 'a_surveiller' => $comptes]]);
    }
}
