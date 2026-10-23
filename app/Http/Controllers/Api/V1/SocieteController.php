<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerSocieteRequest;
use App\Http\Requests\V1\Organisation\ModifierSocieteRequest;
use App\Http\Resources\V1\SocieteResource;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sociétés juridiques. Les modèles de STRUCTURE (organisation,
 * sociétés, cliniques) sont l'arbre au-dessus des global scopes : ils
 * se résolvent toujours explicitement depuis l'organisation du jeton,
 * jamais par binding implicite. Un numéro IDE absent est généré —
 * comme au front.
 */
class SocieteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $societes = $this->organisation()
            ->societes()
            ->withCount('cliniques')
            ->orderBy('id')
            ->paginate($this->parPage($request));

        return SocieteResource::collection($societes);
    }

    public function store(EnregistrerSocieteRequest $request): SocieteResource
    {
        $societe = $this->organisation()->societes()->create([
            'nom' => $request->validated('nom'),
            // Sans IDE, la société reste sans numéro (jamais un numéro inventé)
            'ide' => $request->validated('ide'),
        ]);

        return new SocieteResource($societe);
    }

    public function update(ModifierSocieteRequest $request, int $societe): SocieteResource
    {
        $fiche = $this->organisation()->societes()->findOrFail($societe);
        $fiche->update($request->validated());

        return new SocieteResource($fiche->loadCount('cliniques'));
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }
}
