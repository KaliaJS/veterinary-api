<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\PlanifierSoinRequest;
use App\Http\Resources\V1\SejourSoinResource;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Services\GestionnaireStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan de soins d'un séjour — enfants du dossier, toujours résolus via
 * le séjour. « Soin fait » est irréversible et sort le stock de la
 * clinique du séjour quand un produit est lié : effet système (via
 * GestionnaireStock, mouvement journalisé), sans exiger le domaine
 * stocks. Une rupture n'empêche pas le soin — elle est signalée.
 */
class SoinSejourController extends Controller
{
    public function __construct(private GestionnaireStock $stock) {}

    public function store(PlanifierSoinRequest $request, Sejour $sejour): SejourSoinResource
    {
        $this->authorize('update', $sejour);

        if ($sejour->estSorti()) {
            throw ValidationException::withMessages(['sejour' => 'Un séjour sorti ne se modifie plus.']);
        }

        return new SejourSoinResource($sejour->soins()->create($request->validated())->load('produit'));
    }

    public function faire(Request $request, Sejour $sejour, int $soin): SejourSoinResource
    {
        $this->authorize('update', $sejour);

        /** @var SejourSoin $soin */
        $soin = $sejour->soins()->findOrFail($soin);

        if ($soin->fait) {
            throw ValidationException::withMessages(['soin' => 'Ce soin est déjà enregistré.']);
        }

        $sorti = DB::transaction(function () use ($request, $soin): ?int {
            $soin->update([
                'fait' => true,
                'fait_le' => now(),
                'fait_par_id' => $request->user()?->id,
            ]);

            if ($soin->produit_id === null) {
                return null;
            }

            return $this->stock->sortir(
                $soin->produit,
                1,
                'Soin d\'hospitalisation — '.$soin->acte,
                $request->user(),
                $soin,
            );
        });

        $resource = new SejourSoinResource($soin->load('produit'));

        if ($soin->produit_id !== null) {
            // Écart réellement appliqué : 0 = produit en rupture dans
            // cette clinique (le soin reste fait, parité front).
            $resource->additional(['stock' => ['sorti' => $sorti === -1, 'rupture' => $sorti === 0]]);
        }

        return $resource;
    }
}
