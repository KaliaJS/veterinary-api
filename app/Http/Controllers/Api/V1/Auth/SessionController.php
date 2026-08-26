<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JetonEmisResource;
use App\Http\Resources\V1\UtilisateurResource;
use App\Models\JetonApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    /** Profil de session : utilisateur, clinique du jeton, abilities. */
    public function moi(Request $request, ContexteClinique $contexte): JsonResponse
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();

        return response()->json([
            'data' => [
                'user' => new UtilisateurResource($request->user()),
                'clinique' => [
                    'id' => $contexte->cliniqueId(),
                    'nom' => $contexte->clinique()->nom,
                ],
                'abilities' => $jeton->abilities,
                'expire_le' => $jeton->expires_at?->toIso8601String(),
            ],
        ]);
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

    public function deconnexion(Request $request): Response
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();
        $jeton->delete();

        return response()->noContent();
    }
}
