<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LotResource;
use App\Models\Lot;
use App\Services\GestionnaireStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Lots de péremption de la clinique du jeton, du plus urgent au plus
 * lointain (FEFO). Écarter un lot est une suppression tolérée : le
 * stock sort avec un mouvement motivé « Péremption », puis le lot
 * disparaît.
 */
class LotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lot::class);

        $lots = Lot::query()
            ->when($request->integer('produit_id'), fn ($requete, $produit) => $requete->where('produit_id', $produit))
            ->orderBy('peremption_le')
            ->orderBy('id')
            ->paginate($this->parPage($request, 100));

        return LotResource::collection($lots);
    }

    public function ecarter(Request $request, Lot $lot, GestionnaireStock $stock): Response
    {
        $this->authorize('delete', $lot);

        DB::transaction(function () use ($request, $lot, $stock): void {
            $stock->appliquer(
                $lot->produit,
                -$lot->quantite,
                'Péremption — lot '.$lot->numero_lot.' écarté',
                $request->user(),
                MotifAjustementStock::Peremption,
                $lot,
            );

            $lot->delete();
        });

        return response()->noContent();
    }
}
