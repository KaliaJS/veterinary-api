<?php

namespace App\Http\Requests\V1\Laboratoire;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie des valeurs de résultats — le drapeau H/L est dérivé côté
 * serveur, jamais accepté en entrée. Une valeur nulle efface une
 * saisie erronée (tant que l'analyse n'est pas validée).
 */
class SaisirResultatsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resultats' => ['required', 'array', 'min:1'],
            'resultats.*.id' => ['required', 'integer'],
            'resultats.*.valeur' => ['present', 'nullable', 'numeric', 'between:-999999,999999'],
        ];
    }
}
