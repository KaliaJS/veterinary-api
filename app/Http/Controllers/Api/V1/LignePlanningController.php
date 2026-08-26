<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LignesPlanning\EnregistrerLignePlanningRequest;
use App\Http\Requests\V1\LignesPlanning\ModifierLignePlanningRequest;
use App\Http\Resources\V1\LignePlanningResource;
use App\Models\LignePlanning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Colonnes du planning — pas de destroy : une ligne porte des
 * rendez-vous jamais supprimés (FK restrict), elle se retire de
 * l'affichage, pas de la base.
 */
class LignePlanningController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LignePlanning::class);

        return LignePlanningResource::collection(
            LignePlanning::query()
                ->orderBy('ordre')
                ->orderBy('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerLignePlanningRequest $request): LignePlanningResource
    {
        $this->authorize('create', LignePlanning::class);

        return new LignePlanningResource(LignePlanning::create($request->validated()));
    }

    public function show(LignePlanning $lignePlanning): LignePlanningResource
    {
        $this->authorize('view', $lignePlanning);

        return new LignePlanningResource($lignePlanning);
    }

    public function update(ModifierLignePlanningRequest $request, LignePlanning $lignePlanning): LignePlanningResource
    {
        $this->authorize('update', $lignePlanning);

        $lignePlanning->update($request->validated());

        return new LignePlanningResource($lignePlanning);
    }
}
