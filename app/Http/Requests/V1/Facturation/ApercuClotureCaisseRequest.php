<?php

namespace App\Http\Requests\V1\Facturation;

use App\Services\Facturation\ArreteDeCaisse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aperçu de la clôture de caisse (26.10.2026) : le fond saisi le premier
 * jour, les billets et pièces comptés (centimes : nombre), le nouveau fond
 * et le dépôt, tous facultatifs pendant la saisie.
 */
class ApercuClotureCaisseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fond' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'comptage' => ['nullable', 'array:'.implode(',', ArreteDeCaisse::COUPURES)],
            'comptage.*' => ['integer', 'min:0', 'max:9999'],
            'nouveau_fond' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'depot' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }
}
