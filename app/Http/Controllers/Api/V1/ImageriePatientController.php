<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Examens d'imagerie du dossier patient (patients[].imagings du
 * front) — le dossier médical est partagé : la fiche montre les
 * examens de TOUTES les cliniques de l'organisation, borné par le
 * patient (scopé organisation), scope clinique levé.
 */
class ImageriePatientController extends Controller
{
    public function __invoke(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return ExamenImagerieResource::collection(
            $patient->examensImagerie()
                ->withoutGlobalScope('clinique')
                ->with(ExamenImagerieController::relations())
                ->orderByDesc('realise_le')
                ->paginate($this->parPage($request)),
        );
    }
}
