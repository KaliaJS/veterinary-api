<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Enums\MotifAjustementStock;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LotRegisterTakeResource;
use App\Http\Resources\V1\LotResource;
use App\Http\Resources\V1\LotTakeResource;
use App\Models\Lot;
use App\Models\LotAllocation;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\RegistreStupefiants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Lots de péremption de la clinique du jeton, du plus urgent au plus
 * lointain (FEFO). Chaque sortie de stock les décompte (04.11.2026) : un
 * lot vide n'est plus listé, il reste pour la trace de ses sorties. Écarter
 * un lot est une suppression tolérée : le stock sort de ce lot avec un
 * mouvement motivé « Péremption », puis le lot disparaît.
 */
class LotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lot::class);

        $lots = Lot::query()
            ->when($request->integer('produit_id'), fn ($requete, $produit) => $requete->where('produit_id', $produit))
            // Vide : tout est sorti, il n'est plus en rayon (sa trace reste, GET …/sorties). Un stupéfiant se
            // compte en ml : sans unité entière, un lot qui en contient encore reste (07.11.2026)
            ->where(fn ($enRayon) => $enRayon->where('quantite', '>', 0)->orWhere(fn ($ml) => $ml
                ->whereHas('produit', fn ($produit) => $produit->withoutGlobalScopes()->where('est_stupefiant', true))
                ->whereRaw(RegistreStupefiants::contenuEnSql().' > 0.0005', [(new MouvementStupefiant)->getMorphClass()])))
            // Les lots sans péremption (matériel, accessoires) en dernier
            ->orderByRaw('peremption_le is null')
            ->orderBy('peremption_le')
            ->orderBy('id')
            ->paginate($this->parPage($request, 100));
        // Le contenu en ml des lots de stupéfiants de la page, en deux lectures
        $stupefiantIds = Produit::query()->withoutGlobalScopes()->whereIn('id', $lots->getCollection()->pluck('produit_id')->unique())->where('est_stupefiant', true)->pluck('id');
        $stupefiants = $lots->getCollection()->filter(fn (Lot $lot): bool => $stupefiantIds->contains($lot->produit_id));
        $contenus = RegistreStupefiants::contenusALire($stupefiants);
        $lots->getCollection()->each(fn (Lot $lot) => $lot->setAttribute('contenu_ml', $contenus[$lot->id] ?? null));

        return LotResource::collection($lots);
    }

    public function ecarter(Request $request, Lot $lot, GestionnaireStock $stock): Response
    {
        $this->authorize('delete', $lot);

        // Un stupéfiant s'élimine de façon prouvable (art. 70 OCStup, OSAV D 05) :
        // comment, dit par qui l'écarte (01.11.2026)
        $elimination = null;
        if ($lot->produit->est_stupefiant) {
            Gate::authorize(DomainePermission::Stupefiants->value);
            $elimination = trim((string) $request->validate([
                'elimination' => ['required', 'string', 'max:200'],
            ], [
                'elimination.required' => 'Pour un stupéfiant, dites comment il est éliminé (par exemple : remis au pharmacien cantonal, envoi recommandé n° …).',
            ])['elimination']);
        }

        DB::transaction(function () use ($request, $lot, $stock, $elimination): void {
            // L'article, puis le lot relu sous verrou, comme toute sortie : une vente au même
            // instant le décompte avant, ou après ; écarté deux fois, il n'est sorti qu'une fois
            Produit::query()->withoutGlobalScopes()->whereKey($lot->produit_id)->lockForUpdate()->first();
            $lot = Lot::query()->whereKey($lot->id)->lockForUpdate()->first() ?? abort(404);

            // Un stupéfiant périmé s'élimine par le registre, avec le droit
            // des stupéfiants : l'élimination y est inscrite, le stock suit (24.10.2026)
            if ($lot->produit->est_stupefiant) {
                // Ce que le registre compte encore, au plus le lot : un lot entamé
                // par les doses inscrites s'élimine pour ce qu'il en reste
                app(RegistreStupefiants::class)->detruire($lot->produit, (float) $lot->quantite, 'Lot '.$lot->numero_lot.' périmé : '.$elimination, $lot->clinique_id, $request->user(), $lot);
            } else {
                $stock->appliquer(
                    $lot->produit,
                    -$lot->quantite,
                    'Péremption : lot '.$lot->numero_lot.' écarté',
                    $request->user(),
                    MotifAjustementStock::Peremption,
                    $lot,
                    lot: $lot,
                );
            }

            $lot->delete();
        });

        return response()->noContent();
    }

    /**
     * À qui ce lot a été donné ou vendu (04.11.2026) : chaque sortie qui y a
     * pris, nette de ce qui lui a été rendu, avec l'animal et le client, la
     * plus récente d'abord. Une sortie entièrement annulée n'y est plus.
     * Un stupéfiant (07.11.2026) : son registre fait foi, ses lignes de sortie
     * sur ce lot, en ml, nettes de leurs corrections.
     */
    public function sorties(Request $request, Lot $lot): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lot::class);

        if (Produit::query()->withoutGlobalScopes()->whereKey($lot->produit_id)->value('est_stupefiant')) {
            $lignes = MouvementStupefiant::query()
                ->where('lot_id', $lot->id)
                ->where('quantite', '<', 0)
                ->where('type', '!=', TypeMouvementStupefiant::Correction->value)
                ->withSum('corrections as corrige', 'quantite')
                ->whereRaw('mouvements_stupefiants.quantite + coalesce((select sum(c.quantite) from mouvements_stupefiants c where c.corrige_id = mouvements_stupefiants.id), 0) < -0.0005')
                ->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes()])
                ->latest('id')
                ->paginate($this->parPage($request, 100));

            return LotRegisterTakeResource::collection($lignes);
        }

        $prises = LotAllocation::query()
            ->where('lot_id', $lot->id)
            ->where('kind', LotAllocation::TAKE)
            ->withSum('returns as returned', 'quantity')
            ->whereRaw('-quantity > coalesce((select sum(r.quantity) from lot_allocations r where r.returns_allocation_id = lot_allocations.id), 0)')
            ->with([
                'mouvement' => fn ($mouvement) => $mouvement->withoutGlobalScopes(),
                'patient' => fn ($patient) => $patient->withoutGlobalScopes(),
                'client' => fn ($client) => $client->withoutGlobalScopes(),
            ])
            ->latest('id')
            ->paginate($this->parPage($request, 100));

        return LotTakeResource::collection($prises);
    }
}
