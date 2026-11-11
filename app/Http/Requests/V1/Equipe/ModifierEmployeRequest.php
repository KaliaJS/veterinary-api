<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\StatutContrat;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierEmployeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'role_libelle' => ['sometimes', 'string', 'max:100'],
            'role_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('roles')],
            'taux' => ['sometimes', 'integer', 'between:10,100'],
            'contrat' => ['sometimes', 'string', 'max:100'],
            'contrat_statut' => ['sometimes', Rule::enum(StatutContrat::class)],
            'salaire_mensuel' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'tel' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', new AdresseSansAccent],
            'conges_droit' => ['sometimes', 'integer', 'between:0,60'],
            // Les jours d'avant Veterinary, à la demi-journée (É22)
            'conges_report_arrivee' => ['sometimes', 'numeric', 'between:-60,60', 'multiple_of:0.5'],
        ];
    }
}
