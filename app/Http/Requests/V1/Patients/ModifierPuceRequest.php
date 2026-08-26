<?php

namespace App\Http\Requests\V1\Patients;

use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie de puce du carnet de santé : 15 chiffres requis, stockés
 * normalisés en groupes de trois.
 */
class ModifierPuceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'puce' => ['required', 'string', 'max:32', new NumeroPuce],
        ];
    }
}
