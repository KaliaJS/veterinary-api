<?php

namespace App\Http\Requests\V1\Boutique;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vitrine en ligne : visibilité, promotion (plafonnée à 90 % comme au
 * front), produit vedette, phrase de présentation (site de la clinique,
 * 07.11.2026 ; vide : aucune).
 */
class ModifierVitrineRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'est_boutique' => ['sometimes', 'boolean'],
            'promo_pct' => ['sometimes', 'integer', 'between:0,90'],
            'est_vedette' => ['sometimes', 'boolean'],
            'shop_description' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
