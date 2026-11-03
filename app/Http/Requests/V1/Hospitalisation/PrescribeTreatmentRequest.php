<?php

namespace App\Http\Requests\V1\Hospitalisation;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un traitement prescrit pendant le séjour (03.11.2026) : le médicament,
 * un produit du catalogue de l'organisation en option (son stock sort à
 * chaque prise donnée), la dose, les heures de prise en minutes locales.
 */
class PrescribeTreatmentRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'product_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('produits')],
            'dose' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Médicaments à la dose (03.11.2026) : la dose par prise, dans l'unité du produit
            'dose_amount' => ['required_with:product_id', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            // Ce que contient une unité du produit, s'il ne le dit pas encore
            'product_content' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'product_dose_unit' => ['required_with:product_content', 'nullable', 'string', 'max:12'],
            'times' => ['required', 'array', 'min:1', 'max:24'],
            'times.*' => ['integer', 'between:0,1439', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'Le nom du médicament est requis.',
            'times.required' => 'Au moins une heure de prise est requise.',
            'times.min' => 'Au moins une heure de prise est requise.',
            'dose_amount.required_with' => 'La dose par prise est requise avec un produit du stock.',
        ];
    }
}
