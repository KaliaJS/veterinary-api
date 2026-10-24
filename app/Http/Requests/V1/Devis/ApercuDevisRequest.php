<?php

namespace App\Http\Requests\V1\Devis;

use App\Enums\TypeLigneFacture;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un devis en cours de saisie, à chiffrer sans l'enregistrer : ses lignes et ses pourcentages. */
class ApercuDevisRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lignes' => ['present', 'array', 'max:200'],
            'lignes.*.quantite' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'lignes.*.prix_chf' => ['required', 'numeric', 'min:0', 'max:99999'],
            'lignes.*.type' => ['sometimes', Rule::enum(TypeLigneFacture::class)],
            'remise_pct' => ['sometimes', 'integer', 'between:0,100'],
            'alea_pct' => ['sometimes', 'integer', 'between:0,100'],
            'acompte_pct' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
