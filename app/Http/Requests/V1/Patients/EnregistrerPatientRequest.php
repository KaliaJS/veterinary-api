<?php

namespace App\Http\Requests\V1\Patients;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La dette n'est jamais saisie (dérivée de la facturation) et la
 * déclaration AMICUS a son endpoint dédié — ni l'une ni l'autre ne
 * passent ici.
 */
class EnregistrerPatientRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', $this->existeDansLOrganisation('clients')],
            'nom' => ['required', 'string', 'max:255'],
            'espece' => ['required', Rule::enum(Espece::class)],
            'race' => ['nullable', 'string', 'max:255'],
            'sexe' => ['required', Rule::enum(Sexe::class)],
            'ne_le' => ['nullable', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            'est_sterilise' => ['sometimes', 'boolean'],
            'puce' => ['nullable', 'string', 'max:32', new NumeroPuce],
            'est_agressif' => ['sometimes', 'boolean'],
            'a_allergies' => ['sometimes', 'boolean'],
            'traitement_en_cours' => ['nullable', 'string'],
            'rappels_coupes' => ['sometimes', 'boolean'],
            'client_depuis' => ['sometimes', 'integer', 'min:1990', 'max:'.aujourdhui()->year],
        ];
    }
}
