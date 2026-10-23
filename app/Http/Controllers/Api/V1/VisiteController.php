<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerVisiteRequest;
use App\Http\Resources\V1\VisiteResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Historique de visites de la fiche. Sans vétérinaire explicite, le
 * signataire est l'employé du jeton.
 */
class VisiteController extends Controller
{
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return VisiteResource::collection(
            $patient->visites()
                ->with(['employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
                ->orderByDesc('visite_le')
                ->orderByDesc('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerVisiteRequest $request, Patient $patient): VisiteResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        $visite = $patient->visites()->create([
            ...$donnees,
            'visite_le' => $donnees['visite_le'] ?? aujourdhui()->toDateString(),
            // Le signataire est toujours la personne connectée
            'employe_id' => app(ContexteClinique::class)->employe()?->id,
        ]);

        return new VisiteResource($visite);
    }
}
