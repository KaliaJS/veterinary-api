<?php

namespace App\Http\Resources\V1;

use App\Models\Employe;
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
    /** @param  Collection<int, Employe>  $rattachements */
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
                ->map(fn (Employe $employe): array => [
                    'id' => $employe->clinique_id,
                    'nom' => $employe->clinique->nom,
                ])
                ->values()
                ->all(),
        ];
    }
}
