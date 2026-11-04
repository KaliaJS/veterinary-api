<?php

namespace App\Http\Requests\V1\Facturation;

use App\Enums\VatContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction d'une ligne avant encaissement : la quantité (le libellé et le
 * prix viennent du catalogue), et pour un article s'il est donné pendant le
 * soin ou remis au propriétaire.
 */
class ModifierLigneFactureRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantite' => ['required_without:vat_context', 'integer', 'min:1', 'max:999'],
            // Un article donné pendant le soin ou remis au propriétaire (TVA par ligne, 03.11.2026) :
            // le contexte se dit, le taux se calcule au serveur
            'vat_context' => ['sometimes', Rule::in([VatContext::Care->value, VatContext::Sale->value])],
        ];
    }
}
