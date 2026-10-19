<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Services\Transcription\ConsommationTranscription;
use Illuminate\Http\JsonResponse;

/**
 * Avant d'enregistrer : la transcription est-elle disponible (carte,
 * plafonds), ce que l'organisation a consommé ce mois-ci, et les règles
 * que l'app applique (durée maximale, silences, taille des morceaux).
 */
class TranscriptionController extends Controller
{
    public function etat(ConsommationTranscription $consommation): JsonResponse
    {
        $this->authorize('viewAny', EnregistrementConsultation::class);

        return response()->json(['data' => $consommation->etat(
            Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId()),
        )]);
    }
}
