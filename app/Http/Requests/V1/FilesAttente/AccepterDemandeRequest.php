<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepter une demande en ligne : sans rien, à l'heure demandée ; avec
 * un début, à cette heure (une ligne libre est choisie si la ligne
 * n'est pas donnée, comme sur la grille).
 */
class AccepterDemandeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ligne_planning_id' => ['nullable', $this->existeDansLaClinique('lignes_planning')],
            'starts_at' => ['required_with:ligne_planning_id', 'nullable', 'date'],
        ];
    }
}
