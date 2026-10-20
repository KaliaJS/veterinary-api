<?php

namespace App\Http\Resources\V1;

use App\Auth\Rattachement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Connexion réussie mais compte rattaché à plusieurs cliniques : pas
 * de jeton, la liste des cliniques pour que le front fasse choisir.
 * Le secret (code e-mail, défi passkey) n'est pas consommé : le front
 * rejoue la même requête avec `clinique_id`.
 */
class ChoixCliniqueResource extends JsonResource
{
    /** @param  Collection<int, Rattachement>  $rattachements */
    public function __construct(private readonly Collection $rattachements)
    {
        parent::__construct($rattachements);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'choix_clinique' => true,
            'cliniques' => $this->rattachements
                ->map(fn (Rattachement $rattachement): array => [
                    'id' => $rattachement->clinique->id,
                    'nom' => $rattachement->clinique->nom,
                ])
                ->values()
                ->all(),
        ];
    }
}
