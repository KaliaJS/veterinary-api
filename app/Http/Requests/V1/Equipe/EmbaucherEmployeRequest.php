<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\StatutContrat;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Embauche (écran Organisation — docs/data-model.md §2) : compte
 * vérifié en amont (QR/code, hors API pour l'instant), contrat déposé
 * (pdf) ou généré (signé / en attente de la signature de l'employé).
 * Cible : la clinique du jeton par défaut, une clinique sœur de
 * l'organisation, ou toutes.
 */
class EmbaucherEmployeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'poste' => ['required', 'string', 'max:100'],
            'role_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('roles')],
            'taux' => ['sometimes', 'integer', 'between:10,100'],
            'salaire_mensuel' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'entree_le' => ['sometimes', 'date'],
            'contrat' => ['sometimes', 'string', 'max:100'],
            'contrat_statut' => ['required', Rule::enum(StatutContrat::class)],
            'tel' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'conges_droit' => ['sometimes', 'integer', 'between:0,60'],
            'clinique_id' => ['sometimes', $this->estUneCliniqueDeLOrganisation()],
            'toutes_les_cliniques' => ['sometimes', 'boolean'],
        ];
    }
}
