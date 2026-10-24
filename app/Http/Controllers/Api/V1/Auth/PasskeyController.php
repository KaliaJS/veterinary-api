<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\RevocationJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\EnregistrerPasskeyRequest;
use App\Http\Resources\V1\PasskeyResource;
use App\Services\PasskeyRegisterService;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Gestion des passkeys du compte connecté : enrôlement (options puis
 * dépôt de l'attestation) et révocation.
 */
class PasskeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PasskeyResource::collection($request->user()->passkeys()->latest()->get());
    }

    public function options(Request $request, PasskeyRegisterService $service): JsonResponse
    {
        // App iPhone : passkeys du domaine public du site, par iOS
        $request->validate(['plateforme' => ['nullable', 'in:ios']]);

        return response()->json($service->generateOptions($request->user(), $request->input('plateforme')));
    }

    public function enregistrer(
        EnregistrerPasskeyRequest $request,
        PasskeyRegisterService $enregistrement,
        PasskeyService $passkeys,
    ): PasskeyResource {
        $credentialRecord = $enregistrement->validate(
            $request->credentialJson(),
            $request->string('defi_id')->toString(),
            $request->user(),
        );

        return new PasskeyResource($passkeys->save(
            $request->user(),
            $request->string('nom')->toString(),
            $credentialRecord,
        ));
    }

    public function supprimer(Request $request, int $passkeyId): Response
    {
        $request->user()->passkeys()->findOrFail($passkeyId)->delete();
        // Appareil perdu ou passkey retirée : les autres sessions se ferment,
        // celle d'où l'on agit reste
        app(RevocationJetons::class)->autresQueLeCourant($request->user());

        return response()->noContent();
    }
}
