<?php

namespace App\Http\Resources\V1;

use App\Models\Employe;
use App\Models\JetonApi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Réponse d'émission de jeton (connexion, rafraîchissement, changement
 * de clinique) : le jeton en clair suivi du profil de session complet
 * (SessionResource) — même forme que GET /auth/moi.
 */
class JetonEmisResource extends JsonResource
{
    public function __construct(
        private readonly NewAccessToken $jeton,
        private readonly Employe $employe,
    ) {
        parent::__construct($jeton);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JetonApi $modele */
        $modele = $this->jeton->accessToken;

        return [
            'token' => $this->jeton->plainTextToken,
            ...(new SessionResource($modele->tokenable, $this->employe, $modele))->toArray($request),
        ];
    }
}
