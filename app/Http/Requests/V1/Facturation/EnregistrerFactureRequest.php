<?php

namespace App\Http\Requests\V1\Facturation;

use App\Enums\Espece;
use App\Enums\TypeLigneFacture;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Facture manuelle (sortie d'hospitalisation, comptoir…) : lignes
 * libres, prix signés (une ligne de produit prend le prix du produit). Le patient reste dénormalisable sans fiche —
 * la FK fait foi quand elle existe.
 */
class EnregistrerFactureRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', $this->existeDansLOrganisation('patients')],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'species' => ['nullable', Rule::enum(Espece::class)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'ligne_planning_id' => ['nullable', $this->existeDansLaClinique('lignes_planning')],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.libelle' => ['required', 'string', 'max:255'],
            'lignes.*.quantite' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'lignes.*.prix_chf' => ['required', 'numeric', 'between:-99999,99999'],
            'lignes.*.type' => ['required', Rule::enum(TypeLigneFacture::class)],
            'lignes.*.produit_id' => ['nullable', $this->existeDansLOrganisation('produits')],
        ];
    }
}
