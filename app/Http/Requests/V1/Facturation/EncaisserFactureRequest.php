<?php

namespace App\Http\Requests\V1\Facturation;

use App\Enums\MoyenPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EncaisserFactureRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Sans moyen : seulement une facture réglée entièrement par l'acompte payé en ligne
            'moyen_paiement' => ['nullable', Rule::enum(MoyenPaiement::class)],
            'montant_recu' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            // Paiement partiel (09.11.2026) : sans montant, le reste
            'montant' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:999999'],
            // Un virement : le jour où l'argent est arrivé, et sa référence
            'recu_le' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:140'],
        ];
    }
}
