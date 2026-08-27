<?php

namespace App\Http\Requests\V1\Ordonnances;

use App\Enums\CategorieProduit;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Signature d'une ordonnance : un patient de l'organisation, des lignes
 * de médicaments uniquement (catégorie `med` — parité front), 0 à 3
 * renouvellements.
 */
class EnregistrerOrdonnanceRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', $this->existeDansLOrganisation('patients')],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => [
                'required',
                $this->existeDansLOrganisation('produits')->where('categorie', CategorieProduit::Medicament->value),
            ],
            'lignes.*.posologie' => ['required', 'string', 'max:255'],
            'lignes.*.duree' => ['sometimes', 'string', 'max:255'],
            'renouvellements' => ['sometimes', 'integer', 'between:0,3'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lignes.*.produit_id.exists' => 'Seul un médicament du catalogue de l’organisation se prescrit.',
        ];
    }
}
