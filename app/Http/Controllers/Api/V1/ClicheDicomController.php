<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\EnregistrerClicheDicomRequest;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Models\ExamenImagerie;

/**
 * Flux DICOM entrant d'un examen — la modalité pousse ses instances,
 * possiblement plusieurs fois : idempotent par SOP Instance UID, un
 * cliché ne se double jamais. Les clichés se résolvent via l'examen,
 * jamais par binding direct.
 */
class ClicheDicomController extends Controller
{
    public function __invoke(EnregistrerClicheDicomRequest $request, ExamenImagerie $examen): ExamenImagerieResource
    {
        $this->authorize('update', $examen);

        $donnees = $request->validated();

        $examen->cliches()->firstOrCreate(
            ['sop_instance_uid' => $donnees['sop_instance_uid']],
            $donnees,
        );

        return new ExamenImagerieResource($examen->load(ExamenImagerieController::relations()));
    }
}
