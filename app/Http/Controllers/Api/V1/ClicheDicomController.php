<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\EnregistrerClicheDicomRequest;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Models\ExamenImagerie;
use Illuminate\Validation\ValidationException;

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

        // Signé, l'examen est figé : ses clichés aussi (23.10.2026)
        if ($examen->estSigne()) {
            throw ValidationException::withMessages(['sop_instance_uid' => 'Le compte rendu est signé : l’examen ne reçoit plus de clichés.']);
        }

        $donnees = $request->validated();

        $examen->cliches()->firstOrCreate(
            ['sop_instance_uid' => $donnees['sop_instance_uid']],
            $donnees,
        );

        return new ExamenImagerieResource($examen->load(ExamenImagerieController::relations()));
    }
}
