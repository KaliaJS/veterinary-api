<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConsentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\SignerConsentementRequest;
use App\Models\ConsentRequest;
use App\Services\Bloc\ConsentRecorder;
use Illuminate\Http\JsonResponse;

/**
 * La page du lien reçu par SMS ou par e-mail (02.11.2026) : le propriétaire
 * lit le consentement et le signe sur son téléphone, sans compte. Le lien
 * vaut 24 heures et une seule signature ; un nouvel envoi l'annule.
 */
class PublicConsentController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $request = $this->find($token);

        return response()->json(['data' => [
            'status' => $this->status($request),
            ...($this->status($request) === 'open' ? $request->summary() : ['clinic' => ['nom' => $request->intervention->clinique->nom, 'tel' => $request->intervention->clinique->tel]]),
        ]]);
    }

    public function sign(SignerConsentementRequest $http, string $token, ConsentRecorder $recorder): JsonResponse
    {
        $request = $this->find($token);
        abort_unless($this->status($request) === 'open', 410, match ($this->status($request)) {
            'signed' => 'Ce consentement est déjà signé.',
            'expired' => 'Ce lien n’est plus valable : demandez-en un nouveau à la clinique.',
            default => 'Ce lien a été remplacé par un plus récent, ou le consentement a été recueilli autrement.',
        });

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

    private function find(string $token): ConsentRequest
    {
        $request = ConsentRequest::byToken($token);
        abort_if($request === null, 404, 'Ce lien n’existe pas.');
        $request->load(['intervention' => fn ($q) => $q->withoutGlobalScopes(), 'intervention.patient.client', 'intervention.clinique']);

        return $request;
    }

    private function status(ConsentRequest $request): string
    {
        return match (true) {
            $request->signed_at !== null => 'signed',
            $request->cancelled_at !== null => 'cancelled',
            $request->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }
}
