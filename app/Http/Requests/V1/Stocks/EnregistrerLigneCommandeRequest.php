<?php

namespace App\Http\Requests\V1\Stocks;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/** Ligne ajoutée à un brouillon de commande. */
class EnregistrerLigneCommandeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'produit_id' => ['required', $this->existeDansLOrganisation('produits')],
            'quantite' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }
}
