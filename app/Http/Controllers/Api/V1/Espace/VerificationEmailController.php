<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\CodeEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\VerifierEmailCompteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification de l'adresse d'un compte déjà connecté (inscrit par
 * passkey, l'adresse n'a pas été prouvée) : même code à 6 chiffres que
 * la connexion. L'adresse prouvée sert aux échanges avec les cliniques ;
 * elle ne rattache aucune fiche (le rattachement passe par la clinique).
 */
class VerificationEmailController extends Controller
{
    public function envoyer(Request $request, CodeEmail $codeEmail): JsonResponse
    {
        if ($request->user()->email_verified_at !== null) {
            return response()->json(['message' => 'Adresse déjà vérifiée.'], 200);
        }

        // Déjà connecté : le code se saisit dans l'espace, sans lien de connexion.
        $codeEmail->envoyer($request->user()->email, lienConnexion: false);

        return response()->json(['message' => 'Un code a été envoyé.'], 202);
    }

    public function verifier(
        VerifierEmailCompteRequest $request,
        CodeEmail $codeEmail,
    ): JsonResponse {
        $user = $request->user();

        $codeEmail->controler($user->email, $request->string('code')->toString());
        $codeEmail->consommer($user->email);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(['email_verifie' => true]);
    }
}
