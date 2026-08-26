<?php

namespace App\Http\Resources\V1;

use App\Models\Employe;
use App\Models\JetonApi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Réponse d'émission de jeton (connexion et rafraîchissement) :
 * le jeton en clair, son expiration, l'utilisateur (contrat front),
 * la clinique de rattachement et les abilities accordées.
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
            'expire_le' => $modele->expires_at?->toIso8601String(),
            'user' => new UtilisateurResource($modele->tokenable),
            'clinique' => [
                'id' => $this->employe->clinique_id,
                'nom' => $this->employe->clinique->nom,
            ],
            'abilities' => $modele->abilities,
        ];
    }
}
