<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\CodeEmail;
use App\Auth\RattachementFichesClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\VerifierEmailCompteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification de l'adresse d'un compte déjà connecté (inscrit par
 * passkey, l'adresse n'a pas été prouvée) : même code à 6 chiffres que
 * la connexion. Une fois l'adresse prouvée, les fiches client en
 * attente sont rattachées dans la foulée.
 */
class VerificationEmailController extends Controller
{
    public function envoyer(Request $request, CodeEmail $codeEmail): JsonResponse
    {
        if ($request->user()->email_verified_at !== null) {
            return response()->json(['message' => 'Adresse déjà vérifiée.'], 200);
        }

        $codeEmail->envoyer($request->user()->email);

        return response()->json(['message' => 'Un code a été envoyé.'], 202);
    }

    public function verifier(
        VerifierEmailCompteRequest $request,
        CodeEmail $codeEmail,
        RattachementFichesClient $rattachement,
    ): JsonResponse {
        $user = $request->user();

        $codeEmail->controler($user->email, $request->string('code')->toString());
        $codeEmail->consommer($user->email);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json([
            'email_verifie' => true,
            'fiches_rattachees' => $rattachement->rattacher($user),
        ]);
    }
}
