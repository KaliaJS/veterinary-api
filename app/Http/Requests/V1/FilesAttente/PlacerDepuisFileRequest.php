<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Placement d'une entrée de file sur le planning : une heure (sur la
 * ligne donnée, sinon une ligne libre), ou, sans rien, le premier
 * créneau libre à partir de maintenant, aujourd'hui ou les jours suivants.
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
            'ligne_planning_id' => ['nullable', $this->existeDansLaClinique('lignes_planning')],
            'starts_at' => ['required_with:ligne_planning_id', 'nullable', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'min:10', 'max:240'],
        ];
    }
}
