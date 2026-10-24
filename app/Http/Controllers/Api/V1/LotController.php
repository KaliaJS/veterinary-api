<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LotResource;
use App\Models\Lot;
use App\Services\GestionnaireStock;
use App\Services\RegistreStupefiants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            // Un stupéfiant périmé se détruit par le registre, avec le droit
            // des stupéfiants : la destruction y est inscrite, le stock suit (24.10.2026)
            if ($lot->produit->est_stupefiant) {
                Gate::authorize(DomainePermission::Stupefiants->value);
                // Ce que le registre compte encore, au plus le lot : un lot entamé
                // par les doses inscrites se détruit pour ce qu'il en reste
                app(RegistreStupefiants::class)->detruire($lot->produit, (float) $lot->quantite, 'Destruction : lot '.$lot->numero_lot.' périmé', $lot->clinique_id, $request->user());
            } else {
                $stock->appliquer(
                    $lot->produit,
                    -$lot->quantite,
                    'Péremption : lot '.$lot->numero_lot.' écarté',
                    $request->user(),
                    MotifAjustementStock::Peremption,
                    $lot,
                );
            }

            $lot->delete();
        });

        return response()->noContent();
    }
}
