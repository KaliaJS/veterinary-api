<?php

namespace App\Http\Requests\V1\Espace;

use App\Enums\Sexe;
use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Identité d'un animal que le compte gère lui-même (espace client). */
class ModifierAnimalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'race' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sexe' => ['sometimes', Rule::enum(Sexe::class)],
            'ne_le' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'est_sterilise' => ['sometimes', 'boolean'],
            'puce' => ['sometimes', 'nullable', 'string', 'max:32', new NumeroPuce],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nom.string' => 'Le nom de l’animal est requis',
            'ne_le.before_or_equal' => 'La date de naissance ne peut pas être dans le futur',
        ];
    }
}
