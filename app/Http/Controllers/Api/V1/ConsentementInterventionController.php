<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ConsentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\SignerConsentementRequest;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Intervention;
use App\Services\Bloc\ConsentRecorder;

/**
 * Signature du consentement éclairé à la clinique : archive un document au
 * dossier du patient, horodate la signature et coche le point `consent`.
 * Depuis le 23.10.2026, la preuve est gardée : l'image de la signature
 * (disque privé), le nom de qui a signé et l'employé qui l'a recueillie.
 */
class ConsentementInterventionController extends Controller
{
    public function __invoke(SignerConsentementRequest $request, Intervention $intervention, ConsentRecorder $recorder): InterventionResource
    {
        $this->authorize('update', $intervention);

        $recorder->sign(
            $intervention,
            (string) $request->validated('signataire'),
            (string) $request->signaturePng(),
            ConsentMethod::Signed,
            app(ContexteClinique::class)->employe(),
        );

        return new InterventionResource($intervention->fresh()->load(InterventionController::relations()));
    }
}
