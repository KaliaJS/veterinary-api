<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\CodeEmail;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Api\V1\Auth\Concerns\ConnecteAvecChoixDeClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierCodeEmailRequest;
use App\Http\Requests\V1\Auth\VerifierEmailRequest;
use App\Http\Resources\V1\ChoixCliniqueResource;
use App\Http\Resources\V1\JetonEmisResource;
use Illuminate\Http\JsonResponse;

/**
 * Connexion — et inscription — par code e-mail (sendEmailCode /
 * verifyEmailCode du contrat front) : un e-mail inconnu reçoit un code
 * et sa vérification crée le compte. Le code lui-même n'est jamais
 * exposé par l'API.
 */
class CodeEmailController extends Controller
{
    use ConnecteAvecChoixDeClinique;

    public function envoyer(VerifierEmailRequest $request, CodeEmail $codeEmail): JsonResponse
    {
        $codeEmail->envoyer($request->string('email')->toString());

        // La même réponse pour toute adresse ; l'écran dit seulement que
        // l'accès est sur invitation, pour tout le monde (décision de Jim, 28.10.2026)
        return response()->json([
            'message' => 'Un code a été envoyé.',
            'data' => ['sur_invitation' => (bool) config('plateforme.acces_sur_invitation')],
        ], 202);
    }

    /**
     * Le code est contrôlé, le rattachement résolu, puis seulement le
     * code consommé : un compte multi-clinique reçoit la liste des
     * cliniques sans brûler son code.
     */
    public function verifier(
        VerifierCodeEmailRequest $request,
        CodeEmail $codeEmail,
        EmetteurJetons $emetteur,
    ): JetonEmisResource|ChoixCliniqueResource {
        $email = $request->string('email')->toString();

        $user = $codeEmail->controler($email, $request->string('code')->toString());

        return $this->connecter($request, $emetteur, $user, fn () => $codeEmail->consommer($email));
    }
}
