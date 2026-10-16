<?php

namespace App\Http\Requests\V1\Patients;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierPatientRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', $this->existeDansLOrganisation('clients')],
            'nom' => ['sometimes', 'string', 'max:255'],
            'espece' => ['sometimes', Rule::enum(Espece::class)],
            'race' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sexe' => ['sometimes', Rule::enum(Sexe::class)],
            'ne_le' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            // Jour local suisse (l'application compte en UTC)
            'decede_le' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now('Europe/Zurich')->toDateString()],
            'est_sterilise' => ['sometimes', 'boolean'],
            'puce' => ['sometimes', 'nullable', 'string', 'max:32', new NumeroPuce],
            'est_agressif' => ['sometimes', 'boolean'],
            'a_allergies' => ['sometimes', 'boolean'],
            'traitement_en_cours' => ['sometimes', 'nullable', 'string'],
            'rappels_coupes' => ['sometimes', 'boolean'],
            'client_depuis' => ['sometimes', 'integer', 'min:1990', 'max:'.now()->year],
        ];
    }
}
