<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Injection du carnet (16.10.2026) : un produit du catalogue (`produit_id`,
 * ou son code-barres `gtin` qui le retrouve), ou une saisie libre (`nom`,
 * `marque`, `valences` facultatives). Sans date : aujourd hui ; sans
 * échéance : calculée du schéma du produit. Lot, péremption du flacon,
 * voie, site d injection, remarque et réaction sont facultatifs. Le
 * vétérinaire signataire est toujours l'employé du jeton (23.10.2026).
 */
class EnregistrerVaccinationRequest extends FormRequest
{
    use ReglesScopees;

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['injectee_le' => aujourdhui()->toDateString()]);
        if ($this->filled('gtin')) {
            $this->merge(['gtin' => preg_replace('/\D/', '', (string) $this->input('gtin'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'produit_id' => ['nullable', 'integer', Rule::exists('produits_veterinaires', 'id')],
            'gtin' => ['nullable', 'string', 'digits_between:8,14'],
            'nom' => ['required_without_all:produit_id,gtin', 'nullable', 'string', 'max:255'],
            'marque' => ['nullable', 'string', 'max:255'],
            'valences' => ['nullable', 'array', 'max:12'],
            'valences.*' => ['string', 'max:40', Rule::exists('valences', 'code')],
            'lot' => ['nullable', 'string', 'max:60'],
            'peremption_le' => ['nullable', 'date'],
            'voie' => ['nullable', 'string', 'max:40'],
            'site_injection' => ['nullable', 'string', 'max:60'],
            'remarque' => ['nullable', 'string', 'max:2000'],
            'reaction' => ['nullable', 'string', 'max:2000'],
            'injectee_le' => ['sometimes', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            'echeance_le' => ['nullable', 'date', 'after:injectee_le'],
        ];
    }
}
