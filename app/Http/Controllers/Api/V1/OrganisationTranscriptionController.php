<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Transcription\ReglagesTranscriptionRequest;
use App\Models\Organisation;
use App\Services\Transcription\ConsommationTranscription;
use Illuminate\Http\JsonResponse;

/**
 * Réglages de transcription de l'organisation (domaine organisation) :
 * plafond mensuel et mots rares à faire reconnaître, avec la consommation
 * du mois.
 */
class OrganisationTranscriptionController extends Controller
{
    public function __construct(private ConsommationTranscription $consommation) {}

    public function afficher(): JsonResponse
    {
        return $this->reponse($this->organisation());
    }

    public function modifier(ReglagesTranscriptionRequest $request): JsonResponse
    {
        $organisation = $this->organisation();
        $organisation->forceFill([
            'plafond_transcription' => (int) round((float) $request->validated('plafond') * 100),
            'termes_transcription' => $request->validated('termes'),
        ])->save();

        return $this->reponse($organisation);
    }

    private function reponse(Organisation $organisation): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->consommation->etat($organisation),
            'termes' => $organisation->termes_transcription ?? [],
        ]]);
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId());
    }
}
