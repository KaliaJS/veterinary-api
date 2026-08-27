<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reprogrammation ou brouillon de compte rendu d'un examen non signé
 * — le contrôleur refuse tout sur un examen signé, et la liaison au
 * RDV ne se rejoue pas après coup (elle se pose à l'enregistrement).
 */
class ModifierExamenRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:255'],
            'incidence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'realise_le' => ['sometimes', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'constatations' => ['sometimes', 'nullable', 'string'],
            'conclusion' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
