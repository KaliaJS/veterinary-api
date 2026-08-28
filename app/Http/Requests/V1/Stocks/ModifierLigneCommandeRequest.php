<?php

namespace App\Http\Requests\V1\Stocks;

use Illuminate\Foundation\Http\FormRequest;

/** Quantité d'une ligne de brouillon (le produit ne change pas : on retire la ligne). */
class ModifierLigneCommandeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantite' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }
}
