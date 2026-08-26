<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\DefiPasskey;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\EnregistrerPasskeyRequest;
use App\Http\Resources\V1\PasskeyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;

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

    public function options(
        Request $request,
        GenerateRegistrationOptions $generer,
        DefiPasskey $defis,
    ): JsonResponse {
        $options = $generer($request->user());

        return response()->json([
            'defi_id' => $defis->conserver($options),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function enregistrer(
        EnregistrerPasskeyRequest $request,
        StorePasskey $enregistrer,
        DefiPasskey $defis,
    ): PasskeyResource {
        $passkey = $enregistrer(
            $request->user(),
            $request->string('nom')->toString(),
            $request->credential(),
            $defis->reprendreEnregistrement($request->string('defi_id')->toString()),
        );

        return new PasskeyResource($passkey);
    }

    public function supprimer(Request $request, int $passkeyId): Response
    {
        $request->user()->passkeys()->findOrFail($passkeyId)->delete();

        return response()->noContent();
    }
}
