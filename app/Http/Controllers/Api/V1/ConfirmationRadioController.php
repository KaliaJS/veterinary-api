<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\ConfirmationRadioRequest;
use App\Http\Resources\V1\ConfirmationImagerieResource;
use App\Models\Patient;
use App\Support\Imagerie\RadiosDuDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Un diagnostic de l'examen confirmé ou infirmé après coup (28.10.2026) :
 * la chirurgie, l'histologie ou l'évolution le disent souvent des jours
 * plus tard, parfois dans une clinique sœur. Le document qui le prouve
 * est un document du même animal.
 */
class ConfirmationRadioController extends Controller
{
    public function index(Patient $patient, int $examen): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return ConfirmationImagerieResource::collection(RadiosDuDossier::examen($patient, $examen)->confirmations()->with(['auteur', 'document'])->get());
    }

    public function store(ConfirmationRadioRequest $request, Patient $patient, int $examen): JsonResponse
    {
        $this->authorize('update', $patient);
        $donnees = $request->validated();
        if (($donnees['document_id'] ?? null) !== null && ! $patient->documents()->whereKey($donnees['document_id'])->exists()) {
            throw ValidationException::withMessages(['document_id' => 'Ce document n’est pas dans le dossier de cet animal.']);
        }
        $confirmation = RadiosDuDossier::examen($patient, $examen)->confirmations()->create([...$donnees, 'auteur_id' => $request->user()?->id]);

        return (new ConfirmationImagerieResource($confirmation->load(['auteur', 'document'])))->response()->setStatusCode(201);
    }

    public function destroy(Patient $patient, int $examen, int $confirmation): Response
    {
        $this->authorize('update', $patient);
        RadiosDuDossier::examen($patient, $examen)->confirmations()->findOrFail($confirmation)->delete();

        return response()->noContent();
    }
}
