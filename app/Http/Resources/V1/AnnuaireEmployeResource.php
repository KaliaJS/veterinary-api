<?php

namespace App\Http\Resources\V1;

use App\Models\Employe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée d'annuaire (grille de planning) : identité, ligne et poste —
 * jamais les champs RH d'EmployeResource.
 *
 * @mixin Employe
 */
class AnnuaireEmployeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'nom' => $this->nom,
            'initiales' => $this->initiales,
            'titre' => $this->titre(),
            'role_libelle' => $this->role_libelle,
            'ligne_planning_id' => $this->lignePlanning?->id,
            // État d'affichage (colonne retirée de la grille), pas une
            // donnée RH : le geste de suspension reste sous /equipe.
            'acces_suspendu' => $this->acces_suspendu,
        ];
    }
}
