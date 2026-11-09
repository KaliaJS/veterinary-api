<?php

namespace App\Http\Requests\V1\Facturation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Paiement Stripe d'une facture : carte sur un terminal de la clinique, ou TWINT. */
class PayerFactureRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'moyen' => ['required', Rule::in(['tpe', 'twint'])],
            'terminal_id' => ['required_if:moyen,tpe', 'nullable', 'integer'],
            // Paiement partiel (09.11.2026) : sans montant, le reste
            'montant' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['terminal_id.required_if' => 'Choisissez le terminal de paiement.'];
    }
}
