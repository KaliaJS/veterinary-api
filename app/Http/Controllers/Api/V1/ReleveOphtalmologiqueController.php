<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerReleveOphtalmologiqueRequest;
use App\Http\Requests\V1\DossierMedical\ModifierReleveOphtalmologiqueRequest;
use App\Http\Resources\V1\ReleveOphtalmologiqueResource;
use App\Models\Patient;
use App\Models\ReleveOphtalmologique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Relevés ophtalmologiques (Schirmer, PIO, fluorescéine) — CRUD
 * complet : la suppression d'un relevé est tolérée
 * (docs/data-model.md « Décisions ouvertes »).
 */
class ReleveOphtalmologiqueController extends Controller
{
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return ReleveOphtalmologiqueResource::collection(
            $patient->relevesOphtalmologiques()
                ->orderByDesc('releve_le')
                ->orderByDesc('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerReleveOphtalmologiqueRequest $request, Patient $patient): ReleveOphtalmologiqueResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        $releve = $patient->relevesOphtalmologiques()->create([
            ...$donnees,
            'releve_le' => $donnees['releve_le'] ?? aujourdhui()->toDateString(),
        ]);

        return new ReleveOphtalmologiqueResource($releve);
    }

    public function update(ModifierReleveOphtalmologiqueRequest $request, Patient $patient, int $releve): ReleveOphtalmologiqueResource
    {
        $this->authorize('update', $patient);

        /** @var ReleveOphtalmologique $enregistrement */
        $enregistrement = $patient->relevesOphtalmologiques()->findOrFail($releve);
        $enregistrement->update($request->validated());

        return new ReleveOphtalmologiqueResource($enregistrement);
    }

    public function destroy(Patient $patient, int $releve): Response
    {
        $this->authorize('update', $patient);

        $patient->relevesOphtalmologiques()->findOrFail($releve)->delete();

        return response()->noContent();
    }
}
