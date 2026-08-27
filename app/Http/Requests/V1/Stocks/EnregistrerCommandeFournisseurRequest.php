<?php

namespace App\Http\Requests\V1\Stocks;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

class EnregistrerCommandeFournisseurRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fournisseur' => ['required', 'string', 'max:255'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', $this->existeDansLOrganisation('produits')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }
}
