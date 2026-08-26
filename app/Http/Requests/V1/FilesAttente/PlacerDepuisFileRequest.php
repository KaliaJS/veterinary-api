<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Placement d'une entrée de file sur le planning : créneau explicite
 * (ligne + début) ou — s'il est omis — prochain créneau libre du jour.
 * `duree_minutes` permet de raccourcir au créneau offert (liste
 * d'attente) ; défaut : la durée de l'entrée.
 */
class PlacerDepuisFileRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ligne_planning_id' => ['required_with:starts_at', $this->existeDansLaClinique('lignes_planning')],
            'starts_at' => ['required_with:ligne_planning_id', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'min:10', 'max:240'],
        ];
    }
}
