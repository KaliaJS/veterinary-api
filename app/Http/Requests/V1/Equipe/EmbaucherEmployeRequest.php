<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\StatutContrat;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Embauche (écran Organisation — docs/data-model.md §2) : le compte
 * Veterinary de la personne, par le code employé qu'elle donne (vérifié
 * d'abord par GET /equipe/comptes/{code}), contrat déposé (pdf) ou
 * généré (signé / en attente de la signature de l'employé). Cliniques :
 * toutes, ou celles que l'administrateur coche.
 */
class EmbaucherEmployeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'nom' => ['sometimes', 'string', 'max:255'],
            'poste' => ['required', 'string', 'max:100'],
            'role_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('roles')],
            'taux' => ['sometimes', 'integer', 'between:10,100'],
            'salaire_mensuel' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'entree_le' => ['sometimes', 'date'],
            'contrat' => ['sometimes', 'string', 'max:100'],
            'contrat_statut' => ['required', Rule::enum(StatutContrat::class)],
            'tel' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', new AdresseSansAccent],
            'conges_droit' => ['sometimes', 'integer', 'between:0,60'],
            'toutes_les_cliniques' => ['sometimes', 'boolean'],
            'cliniques' => ['required_unless:toutes_les_cliniques,true', 'array', 'min:1'],
            'cliniques.*' => ['integer', 'distinct', $this->estUneCliniqueDeLOrganisation()],
        ];
    }
}
