<?php

namespace App\Http\Requests\V1\Devis;

use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un devis en cours de saisie, à chiffrer sans l'enregistrer : ses lignes et ses pourcentages. */
class ApercuDevisRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lignes' => ['present', 'array', 'max:200'],
            'lignes.*.quantite' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'lignes.*.prix_chf' => ['required', 'numeric', 'min:0', 'max:99999'],
            'lignes.*.type' => ['sometimes', Rule::enum(TypeLigneFacture::class)],
            // L'animal (le soin suit son espèce) et ce qui part à la maison (taux de vente, 03.11.2026)
            'patient_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('patients')],
            'lignes.*.produit_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('produits')],
            // Un médicament remis pour la maison suit le taux de vente ; le reste suit le soin (03.11.2026)
            'lignes.*.vat_context' => ['sometimes', 'nullable', Rule::in([VatContext::Care->value, VatContext::Sale->value])],
            'remise_pct' => ['sometimes', 'integer', 'between:0,100'],
            'alea_pct' => ['sometimes', 'integer', 'between:0,100'],
            'acompte_pct' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
