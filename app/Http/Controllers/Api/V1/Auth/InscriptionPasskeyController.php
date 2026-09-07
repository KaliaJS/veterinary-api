<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\CreationCompte;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\InscrirePasskeyRequest;
use App\Http\Requests\V1\Auth\OptionsInscriptionPasskeyRequest;
use App\Http\Resources\V1\JetonEmisResource;
use App\Services\PasskeyRegisterService;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Inscription publique par passkey (calquée sur foodtruck-api) : pour
 * un e-mail inconnu, options de création WebAuthn — c'est cette
 * cérémonie de CRÉATION qui fait proposer au navigateur ses
 * gestionnaires (trousseau iCloud, profil Chrome…) —, puis dépôt de
 * l'attestation : le compte naît avec sa première passkey et une
 * session sans clinique est ouverte.
 */
class InscriptionPasskeyController extends Controller
{
    public function options(OptionsInscriptionPasskeyRequest $request, PasskeyRegisterService $service): JsonResponse
    {
        return response()->json($service->generateOptionsPourInscription($request->string('email')->toString()));
    }

    public function inscrire(
        InscrirePasskeyRequest $request,
        PasskeyRegisterService $enregistrement,
        PasskeyService $passkeys,
        CreationCompte $comptes,
        EmetteurJetons $emetteur,
    ): JsonResponse {
        $email = $request->string('email')->toString();

        $credentialRecord = $enregistrement->validate(
            $request->credentialJson(),
            $request->string('defi_id')->toString(),
            $email,
        );

        /** @var NewAccessToken $jeton */
        $jeton = DB::transaction(function () use ($request, $passkeys, $comptes, $emetteur, $email, $credentialRecord): NewAccessToken {
            $user = $comptes->creer($email);

            $passkeys->save($user, $request->string('nom', 'Passkey')->toString(), $credentialRecord);

            return $emetteur->emettreSansClinique($user, $request->string('appareil', 'api')->toString());
        });

        return (new JetonEmisResource($jeton, null))->response()->setStatusCode(201);
    }
}
