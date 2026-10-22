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
        ];
    }
}
