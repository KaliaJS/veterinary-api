<?php

namespace App\Http\Resources\V1;

use App\Auth\Rattachement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Connexion réussie mais compte rattaché à plusieurs cliniques : pas
 * de jeton d'API, la liste des cliniques pour que le front fasse
 * choisir, et le jeton de choix (`choix`) à rendre avec la clinique
 * choisie (POST auth/choix-clinique).
 */
class ChoixCliniqueResource extends JsonResource
{
    /** @param  Collection<int, Rattachement>  $rattachements */
    public function __construct(private readonly Collection $rattachements, private readonly ?string $choix = null)
    {
        parent::__construct($rattachements);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'choix_clinique' => true,
            'choix' => $this->choix,
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
