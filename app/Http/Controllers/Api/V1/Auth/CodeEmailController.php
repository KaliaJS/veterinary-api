<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\CodeEmail;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierCodeEmailRequest;
use App\Http\Requests\V1\Auth\VerifierEmailRequest;
use App\Http\Resources\V1\JetonEmisResource;
use Illuminate\Http\JsonResponse;

/**
 * Connexion par code e-mail (sendEmailCode / verifyEmailCode du
 * contrat front). La réponse d'envoi est identique que l'e-mail soit
 * connu ou non ; le code lui-même n'est jamais exposé par l'API.
 */
class CodeEmailController extends Controller
{
    public function envoyer(VerifierEmailRequest $request, CodeEmail $codeEmail): JsonResponse
    {
        $codeEmail->envoyer($request->string('email')->toString());

        return response()->json(['message' => 'Si ce compte existe, un code a été envoyé.'], 202);
    }

    public function verifier(
        VerifierCodeEmailRequest $request,
        CodeEmail $codeEmail,
        EmetteurJetons $emetteur,
    ): JetonEmisResource {
        $user = $codeEmail->verifier(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
        );

        $resultat = $emetteur->emettre(
            $user,
            $request->filled('clinique_id') ? $request->integer('clinique_id') : null,
            $request->string('appareil', 'api')->toString(),
        );

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }
}
