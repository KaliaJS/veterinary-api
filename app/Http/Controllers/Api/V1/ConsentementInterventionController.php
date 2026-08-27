<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Intervention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Signature du consentement éclairé (bsOk du front) : archive un
 * document au dossier du patient (métadonnées, comme tout le dossier
 * documentaire), horodate la signature et coche le point `consent` —
 * seule voie pour le cocher.
 */
class ConsentementInterventionController extends Controller
{
    public function __invoke(Request $request, Intervention $intervention): InterventionResource
    {
        $this->authorize('update', $intervention);

        if ($intervention->consentement_signe_le !== null) {
            throw ValidationException::withMessages(['consentement' => 'Le consentement est déjà signé.']);
        }

        DB::transaction(function () use ($intervention): void {
            $patient = $intervention->patient;

            $document = $patient->documents()->create([
                'nom' => 'Consentement éclairé — '.$intervention->procedure,
                'type' => 'consentement',
                'signataire' => $patient->client->nom_affiche,
                'etabli_le' => today()->toDateString(),
            ]);

            $intervention->update([
                'consentement_signe_le' => now(),
                'consentement_document_id' => $document->id,
            ]);

            $intervention->checklist()->where('code', 'consent')->update(['est_coche' => true]);
        });

        return new InterventionResource($intervention->load([
            'patient.client',
            'chirurgien' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'checklist',
            'transitions',
        ]));
    }
}
