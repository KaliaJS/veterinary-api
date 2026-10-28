<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\EmetteurJetons;
use App\Http\Controllers\Api\V1\Auth\Concerns\ConnecteAvecChoixDeClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierPasskeyRequest;
use App\Http\Resources\V1\ChoixCliniqueResource;
use App\Http\Resources\V1\JetonEmisResource;
use App\Rules\AdresseSansAccent;
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
    use ConnecteAvecChoixDeClinique;

    public function options(Request $request, PasskeyAuthService $service): JsonResponse
    {
        $request->validate([
            'email' => ['sometimes', 'email', new AdresseSansAccent],
            // App iPhone : passkeys du domaine public du site, par iOS
            'plateforme' => ['nullable', 'in:ios'],
        ]);

        // E-mail fourni → credentials du compte proposés ; sinon
        // credentials découvrables (le navigateur choisit).
        return response()->json($service->generateOptions(
            $request->filled('email') ? $request->string('email')->toString() : null,
            $request->input('plateforme'),
        ));
    }

    /**
     * L'assertion est contrôlée, le rattachement résolu, puis seulement
     * le défi consommé (et le compteur de signatures enregistré) : un
     * compte multi-clinique reçoit la liste des cliniques et rejoue la
     * même assertion avec `clinique_id`.
     */
    public function verifier(
        VerifierPasskeyRequest $request,
        PasskeyAuthService $service,
        EmetteurJetons $emetteur,
    ): JetonEmisResource|ChoixCliniqueResource {
        $defiId = $request->string('defi_id')->toString();

        ['passkey' => $passkey, 'credentialRecord' => $credentialRecord] = $service->controler(
            $request->credentialJson(),
            $defiId,
        );

        return $this->connecter(
            $request,
            $emetteur,
            $passkey->user,
            fn () => $service->consommer($defiId, $passkey, $credentialRecord),
        );
    }
}
