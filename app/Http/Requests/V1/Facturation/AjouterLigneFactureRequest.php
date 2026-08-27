<?php

namespace App\Http\Requests\V1\Facturation;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajout d'une ligne pendant l'encaissement : un acte du catalogue
 * (prestation) OU un médicament du stock — jamais les deux. Le
 * médicament ajoute aussi la prescription jointe « délivré par la
 * clinique ».
 */
class AjouterLigneFactureRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prestation_id' => ['required_without:produit_id', 'prohibits:produit_id', $this->existeDansLOrganisation('prestations')],
            'produit_id' => ['required_without:prestation_id', $this->existeDansLOrganisation('produits')],
            'posologie' => ['nullable', 'string', 'max:255'],
        ];
    }
}
