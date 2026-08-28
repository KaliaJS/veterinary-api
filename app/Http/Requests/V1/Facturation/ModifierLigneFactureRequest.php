<?php

namespace App\Http\Requests\V1\Facturation;

use Illuminate\Foundation\Http\FormRequest;

/** Correction d'une ligne avant encaissement : la quantité seulement (le libellé et le prix viennent du catalogue). */
class ModifierLigneFactureRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantite' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
