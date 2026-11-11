<?php

namespace App\Http\Requests\V1\Equipe;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Les règles des congés : au moins 4 semaines de droit (CO 329a), le
 * seuil des jours reportés, la date avant laquelle les planifier.
 */
class ModifierReglagesCongesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'droit_defaut' => ['sometimes', 'integer', 'between:20,60'],
            'seuil_report' => ['sometimes', 'integer', 'between:0,30'],
            'planifier_avant' => ['sometimes', 'string', 'in:03-31,04-30,06-30'],
            'rappel_deux_semaines' => ['sometimes', 'boolean'],
            'sans_validation' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'droit_defaut.between' => 'Au moins 20 jours : quatre semaines par an (CO 329a).',
        ];
    }
}
