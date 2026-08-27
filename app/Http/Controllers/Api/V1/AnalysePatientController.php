<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnalyseResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Analyses du dossier patient (ofPatient du front) — le dossier
 * médical est partagé : la fiche montre les demandes de TOUTES les
 * cliniques de l'organisation, borné par le patient (scopé
 * organisation), scope clinique levé.
 */
class AnalysePatientController extends Controller
{
    public function __invoke(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return AnalyseResource::collection(
            $patient->analyses()
                ->withoutGlobalScope('clinique')
                ->with(AnalyseController::relations())
                ->orderByDesc('preleve_le')
                ->paginate($this->parPage($request)),
        );
    }
}
