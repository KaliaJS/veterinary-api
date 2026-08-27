<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\StatutContrat;
use App\Http\Requests\V1\Concerns\ReglesScopees;
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
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'conges_droit' => ['sometimes', 'integer', 'between:0,60'],
            'conges_pris' => ['sometimes', 'integer', 'between:0,60'],
        ];
    }
}
