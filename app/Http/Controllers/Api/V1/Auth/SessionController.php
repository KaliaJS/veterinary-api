<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JetonEmisResource;
use App\Http\Resources\V1\SessionResource;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    /**
     * Profil de session : utilisateur, employé de la clinique du jeton
     * (auteur des gestes), clinique, autres rattachements, permissions
     * effectives — celles du contexte, déjà lues en base par le
     * middleware `clinique`. Jeton sans clinique : contexte vide,
     * employé et clinique à null.
     */
    public function moi(Request $request, ContexteClinique $contexte): SessionResource
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();

        return new SessionResource($request->user(), $contexte->employe(), $jeton, $contexte->permissions());
    }

    /**
     * Rafraîchissement : jeton neuf (abilities recalculées, même
     * clinique), l'ancien est révoqué immédiatement.
     */
    public function rafraichir(Request $request, EmetteurJetons $emetteur): JetonEmisResource
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();

        $resultat = $emetteur->rafraichir($request->user(), $jeton);

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }

    /**
     * Jeton de l'Apple Watch, demandé par l'app iPhone : la montre le
     * garde dans son trousseau et le renouvelle elle-même. Le jeton de
     * l'iPhone reste valable.
     */
    public function montre(Request $request, EmetteurJetons $emetteur): JetonEmisResource
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();

        $resultat = $emetteur->emettrePourMontre($request->user(), $jeton);

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }

    public function deconnexion(Request $request): Response
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();
        $jeton->delete();

        return response()->noContent();
    }

    /** Un nouveau code employé ; l'ancien ne retrouve plus le compte. */
    public function nouveauCodeEmploye(Request $request): JsonResponse
    {
        $code = $request->user()->nouveauCodeEmploye();

        return response()->json(['data' => ['code_employe' => User::formaterCodeEmploye($code)]]);
    }
}
