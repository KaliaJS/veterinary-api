<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\ConsentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\SignerConsentementRequest;
use App\Models\ConsentRequest;
use App\Services\Bloc\ConsentRecorder;
use Illuminate\Http\JsonResponse;

/**
 * Les consentements à signer dans l'espace du propriétaire (02.11.2026) :
 * ceux que la clinique y a envoyés, encore ouverts, pour ses animaux.
 */
class OwnerConsentController extends Controller
{
    public function index(ContexteProprietaire $contexte): JsonResponse
    {
        $requests = $this->query($contexte)->get();

        return response()->json(['data' => $requests->map(fn (ConsentRequest $r): array => ['id' => $r->id, ...$r->summary()])->values()]);
    }

    public function sign(SignerConsentementRequest $http, ContexteProprietaire $contexte, int $consentRequest, ConsentRecorder $recorder): JsonResponse
    {
        $request = $this->query($contexte)->whereKey($consentRequest)->first();
        abort_if($request === null, 404, 'Ce consentement n’est plus à signer.');

        $recorder->sign(
            $request->intervention,
            (string) $http->validated('signataire'),
            (string) $http->signaturePng(),
            ConsentMethod::Remote,
            null,
            $request,
            $http->ip(),
            $http->userAgent(),
        );

        return response()->json(['data' => ['status' => 'signed']]);
    }

    /** Ouverts, envoyés dans l'espace, pour les animaux de ce propriétaire */
    private function query(ContexteProprietaire $contexte)
    {
        return ConsentRequest::withoutGlobalScopes()
            ->open()
            ->whereJsonContains('channels', 'space')
            ->whereHas('intervention', fn ($q) => $q->withoutGlobalScopes()->whereHas('patient', fn ($p) => $p->withoutGlobalScopes()->whereIn('client_id', $contexte->clientIds())))
            ->with(['intervention' => fn ($q) => $q->withoutGlobalScopes(), 'intervention.patient.client', 'intervention.clinique'])
            ->latest();
    }
}
