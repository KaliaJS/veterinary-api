<?php

namespace App\Http\Requests\V1\Devis;

use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seul un brouillon se modifie (le contrôleur garde le statut). Les
 * lignes, si présentes, remplacent le jeu complet. Patient et lignes
 * restent facultatifs dans un brouillon : exigés à l'envoi (01.11.2026).
 */
class ModifierDevisRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('patients')],
            'motif' => ['sometimes', 'string', 'max:255'],
            'lignes' => ['sometimes', 'array'],
            'lignes.*.libelle' => ['required', 'string', 'max:255'],
            'lignes.*.quantite' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'lignes.*.prix_chf' => ['required', 'numeric', 'min:0', 'max:99999'],
            'lignes.*.type' => ['required', Rule::enum(TypeLigneFacture::class)],
            'lignes.*.produit_id' => ['nullable', $this->existeDansLOrganisation('produits')],
            // Un médicament remis pour la maison suit le taux de vente ; le reste suit le soin (03.11.2026)
            'lignes.*.vat_context' => ['sometimes', 'nullable', Rule::in([VatContext::Care->value, VatContext::Sale->value])],
            'remise_pct' => ['sometimes', 'integer', 'between:0,100'],
            'alea_pct' => ['sometimes', 'integer', 'between:0,100'],
            'validite_jours' => ['sometimes', 'integer', 'between:1,365'],
            'acompte_pct' => ['sometimes', 'integer', 'between:0,100'],
            'conditions' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
