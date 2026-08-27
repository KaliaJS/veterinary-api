<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Signature du compte rendu (sign du front) : les constatations sont
 * obligatoires — « Rédigez les constatations avant de signer ».
 */
class SignerCompteRenduRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'constatations' => ['required', 'string'],
            'conclusion' => ['sometimes', 'nullable', 'string'],
            'sans_facture' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'constatations.required' => 'Rédigez les constatations avant de signer.',
        ];
    }
}
