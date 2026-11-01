<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TypeMouvementStupefiant;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MouvementStupefiantResource;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les substances du registre et le bilan d'une période (mise aux normes,
 * 01.11.2026 ; art. 57 OCStup : stock au début et à la fin, achats,
 * utilisations, pertes, éliminations ; OSAV D 06 : bilan visé). Par
 * clinique, celle du jeton. Le PDF se compose au front, comme les autres
 * pièces. Plus de « stock bas » : le réapprovisionnement reste dans les
 * alertes de Stocks, pas dans un registre légal.
 */
class RegistreStupefiantController extends Controller
{
    public function soldes(): JsonResponse
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        return response()->json(['data' => $this->substances()]);
    }

    /**
     * Le bilan d'une période, une fiche par substance : stock au début,
     * chaque ligne avec le stock après elle, les totaux par type, stock à
     * la fin. Sans période : l'année en cours.
     */
    public function exporter(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        $periode = $request->validate([
            'du' => ['sometimes', 'date'],
            'au' => ['sometimes', 'date', 'after_or_equal:du'],
            'produit_id' => ['sometimes', 'integer'],
        ]);
        $du = $request->date('du') ?? aujourdhui()->startOfYear();
        $au = $request->date('au') ?? aujourdhui();
        $debut = minuitLocal($du);
        // Le lendemain à minuit, à l'heure de la clinique (un jour de changement d'heure fait 23 ou 25 h)
        $fin = minuitLocal($au->copy()->addDay());

        $fiches = Produit::query()
            ->where('est_stupefiant', true)
            ->when($periode['produit_id'] ?? null, fn ($requete, int $id) => $requete->whereKey($id))
            ->orderBy('nom')
            ->get()
            ->map(function (Produit $produit) use ($debut, $fin): array {
                $avant = round((float) MouvementStupefiant::query()->where('produit_id', $produit->id)->where('created_at', '<', $debut)->sum('quantite'), 3);
                $lignes = MouvementStupefiant::query()->with(['produit', 'corrige'])
                    ->where('produit_id', $produit->id)
                    ->where('created_at', '>=', $debut)->where('created_at', '<', $fin)
                    ->oldest('created_at')->oldest('id')
                    ->get();
                $total = fn (TypeMouvementStupefiant ...$types): float => round(abs((float) $lignes->filter(fn (MouvementStupefiant $m) => in_array($m->type, $types, true))->sum('quantite')), 3);

                return [
                    'produit' => ['id' => $produit->id, 'nom' => $produit->nom, 'unite' => $produit->unite, 'tableau' => $produit->tableau_stupefiant],
                    'stock_debut' => $avant,
                    'stock_fin' => round($avant + (float) $lignes->sum('quantite'), 3),
                    'totaux' => [
                        'receptions' => $total(TypeMouvementStupefiant::Reception),
                        'utilisations' => $total(TypeMouvementStupefiant::Administration, TypeMouvementStupefiant::Remise),
                        'pertes' => $total(TypeMouvementStupefiant::Perte),
                        'vols' => $total(TypeMouvementStupefiant::Vol),
                        'retours' => $total(TypeMouvementStupefiant::Retour),
                        'eliminations' => $total(TypeMouvementStupefiant::Elimination),
                        'corrections' => round((float) $lignes->where('type', TypeMouvementStupefiant::Correction)->sum('quantite'), 3),
                        'ecarts_inventaire' => round((float) $lignes->where('type', TypeMouvementStupefiant::Inventaire)->sum('quantite'), 3),
                    ],
                    'lignes' => MouvementStupefiantResource::collection($lignes)->resolve(),
                ];
            })
            // Une substance sans rien dans la période ni en stock n'a pas de fiche
            ->filter(fn (array $f): bool => $f['stock_debut'] != 0 || $f['lignes'] !== [])
            ->values();

        return response()->json(['data' => [
            'etabli_le' => now(),
            'periode' => ['du' => $du->toDateString(), 'au' => $au->toDateString()],
            'fiches' => $fiches,
        ]]);
    }

    /**
     * Les substances marquées stupéfiant, avec leur stock au registre de la
     * clinique, leur tableau et la date de la dernière ligne.
     *
     * @return list<array<string, mixed>>
     */
    private function substances(): array
    {
        return Produit::query()
            ->where('est_stupefiant', true)
            ->withSum('mouvementsStupefiants as solde', 'quantite')
            ->withMax('mouvementsStupefiants as dernier_le', 'created_at')
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $produit): array => [
                'produit_id' => $produit->id,
                'nom' => $produit->nom,
                'unite' => $produit->unite,
                'tableau' => $produit->tableau_stupefiant,
                'solde' => round((float) $produit->solde, 3),
                'dernier_le' => $produit->getAttribute('dernier_le'),
            ])
            ->values()
            ->all();
    }
}
