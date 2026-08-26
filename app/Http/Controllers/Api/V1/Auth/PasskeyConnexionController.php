<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierPasskeyRequest;
use App\Http\Resources\V1\JetonEmisResource;
use App\Services\PasskeyAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connexion passkey / WebAuthn — voie primaire (loginWithPasskey du
 * contrat front, découpée en cérémonie réelle) : options puis
 * vérification de l'assertion, défi conservé en cache (API sans
 * session), jeton d'API émis à la clé.
 */
class PasskeyConnexionController extends Controller
{
    public function options(Request $request, PasskeyAuthService $service): JsonResponse
    {
        $request->validate(['email' => ['sometimes', 'email']]);

        // E-mail fourni → credentials du compte proposés ; sinon
        // credentials découvrables (le navigateur choisit).
        return response()->json($service->generateOptions(
            $request->filled('email') ? $request->string('email')->toString() : null,
        ));
    }

    public function verifier(
        VerifierPasskeyRequest $request,
        PasskeyAuthService $service,
        EmetteurJetons $emetteur,
    ): JetonEmisResource {
        $passkey = $service->validate(
            $request->credentialJson(),
            $request->string('defi_id')->toString(),
        );

        $resultat = $emetteur->emettre(
            $passkey->user,
            $request->filled('clinique_id') ? $request->integer('clinique_id') : null,
            $request->string('appareil', 'api')->toString(),
        );

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }
}
