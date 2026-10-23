<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Transcription\MorceauAudioRequest;
use App\Models\EnregistrementConsultation;
use App\Services\Transcription\ReceptionAudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un morceau d'audio d'une partie (voir ReceptionAudio).
 * Accepté pendant l'enregistrement et après « terminer » tant que des
 * parties sont encore en route.
 */
class PartieEnregistrementController extends Controller
{
    public function morceau(MorceauAudioRequest $request, EnregistrementConsultation $enregistrement, int $numero, ReceptionAudio $reception): JsonResponse
    {
        $this->authorize('create', EnregistrementConsultation::class);

        if (! in_array($enregistrement->statut, [StatutEnregistrement::Enregistrement, StatutEnregistrement::Transcription], true)) {
            throw ValidationException::withMessages(['enregistrement' => 'Cet enregistrement n’attend plus d’audio.']);
        }
        if ($numero < 1 || $numero > (int) config('transcription.parties_max') || ($enregistrement->parties_attendues && $numero > $enregistrement->parties_attendues)) {
            throw ValidationException::withMessages(['numero' => 'Cette partie ne fait pas partie de l’enregistrement.']);
        }

        $partie = $reception->morceau(
            $enregistrement,
            $numero,
            (int) $request->validated('morceau'),
            (int) $request->validated('morceaux'),
            (string) $request->validated('format'),
            $request->file('fichier'),
        );

        return response()->json(['data' => [
            'numero' => $partie->numero,
            'statut' => $partie->statut->value,
            'morceaux' => $partie->morceaux,
            'morceaux_recus' => $reception->recus($partie),
        ]]);
    }
}
