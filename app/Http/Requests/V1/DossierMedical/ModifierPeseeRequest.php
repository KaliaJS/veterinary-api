<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction d'une pesée erronée — le poids seulement, la date reste.
 */
class ModifierPeseeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'poids_kg' => ['required', 'numeric', 'gt:0', 'max:150'],
        ];
    }
}
