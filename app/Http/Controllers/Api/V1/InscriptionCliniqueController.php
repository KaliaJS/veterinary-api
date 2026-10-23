<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\EmetteurJetons;
use App\Auth\Rattachement;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inscription\GlnRequest;
use App\Http\Requests\V1\Inscription\InscrireCliniqueRequest;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\JetonApi;
use App\Services\Inscription\InscriptionClinique;
use App\Services\Inscription\VerificationVeterinaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'une clinique par un compte connecté (avec ou sans
 * clinique) : vérification du GLN dans MedReg pendant la saisie, puis
 * création de l'organisation. La réponse est une session neuve sur la
 * nouvelle clinique (l'ancien jeton est révoqué) ; l'organisation y
 * figure avec son état (vérification, abonnement).
 */
class InscriptionCliniqueController extends Controller
{
    /** Ce que MedReg dit du GLN, sans rien enregistrer. */
    public function verifier(GlnRequest $request, VerificationVeterinaire $verification): JsonResponse
    {
        $resultat = $verification->verifier(
            $request->string('gln')->toString(),
            $request->string('prenom')->toString(),
            $request->string('nom')->toString(),
            $request->user(),
        );

        return response()->json(['data' => [
            'verifie' => $resultat->estVerifie(),
            'raison' => $resultat->raison,
            'personne' => $resultat->fiche?->toArray(),
        ]]);
    }

    public function inscrire(InscrireCliniqueRequest $request, InscriptionClinique $inscription, EmetteurJetons $emetteur): JsonResponse
    {
        $user = $request->user();
        // Une clinique ne s'inscrit qu'avec une adresse prouvée (code e-mail)
        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => 'Confirmez d’abord votre adresse e-mail : connectez-vous avec le code reçu par e-mail, puis inscrivez votre clinique.']);
        }
        $employe = $inscription->inscrire($user, $request->validated());

        /** @var JetonApi $ancien */
        $ancien = $user->currentAccessToken();
        $jeton = $emetteur->emettrePour($user, new Rattachement($employe, $employe->cliniques->sole()), $ancien->name);
        $ancien->delete();

        return (new JetonEmisResource($jeton, $employe))->response()->setStatusCode(201);
    }
}
