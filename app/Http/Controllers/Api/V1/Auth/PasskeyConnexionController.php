<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\DefiPasskey;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierPasskeyRequest;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Connexion passkey / WebAuthn — voie primaire (loginWithPasskey du
 * contrat front, découpée en cérémonie réelle) : options puis
 * vérification de l'assertion, défi conservé en cache (API sans
 * session), jeton d'API émis à la clé.
 */
class PasskeyConnexionController extends Controller
{
    public function options(
        Request $request,
        GenerateVerificationOptions $generer,
        DefiPasskey $defis,
    ): JsonResponse {
        $request->validate(['email' => ['sometimes', 'email']]);

        // E-mail fourni → credentials du compte proposés ; sinon
        // credentials découvrables (le navigateur choisit).
        $user = $request->filled('email')
            ? User::where('email', $request->string('email'))->first()
            : null;

        $options = $generer($user);

        return response()->json([
            'defi_id' => $defis->conserver($options),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function verifier(
        VerifierPasskeyRequest $request,
        VerifyPasskey $verifier,
        DefiPasskey $defis,
        EmetteurJetons $emetteur,
    ): JetonEmisResource {
        $passkey = $verifier(
            $request->credential(),
            $defis->reprendreVerification($request->string('defi_id')->toString()),
        );

        /** @var User $user */
        $user = $passkey->user;

        $resultat = $emetteur->emettre(
            $user,
            $request->filled('clinique_id') ? $request->integer('clinique_id') : null,
            $request->string('appareil', 'api')->toString(),
        );

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }
}
