<?php

namespace App\Http\Resources\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Models\Client;
use App\Services\AccueilProprietaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accueil de l'espace propriétaire : le compte (e-mail vérifié ou non,
 * fiches rattachées) et les blocs composés par AccueilProprietaire.
 */
class AccueilProprietaireResource extends JsonResource
{
    public function __construct(private readonly ContexteProprietaire $contexte)
    {
        parent::__construct($contexte->user());
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->contexte->user();

        return [
            'compte' => [
                'email' => $user->email,
                'email_verifie' => $user->email_verified_at !== null,
                'fiches' => $this->contexte->clients()->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'prenom' => $client->prenom,
                    'nom' => $client->nom,
                    'organisation' => ['id' => $client->organisation->id, 'nom' => $client->organisation->nom],
                ])->values()->all(),
            ],
            ...app(AccueilProprietaire::class)->composer($this->contexte),
        ];
    }
}
