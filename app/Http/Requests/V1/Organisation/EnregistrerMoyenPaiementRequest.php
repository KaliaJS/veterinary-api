<?php

namespace App\Http\Requests\V1\Organisation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Carte de l'abonnement, confirmée dans le Payment Element de Stripe :
 * seul son identifiant passe par nos serveurs, jamais le numéro.
 */
class EnregistrerMoyenPaiementRequest extends FormRequest
{
    /**
     * Carte confirmée par le Payment Element de Stripe (identifiant « pm_… »).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', 'starts_with:pm_', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Carte manquante.',
            'payment_method.starts_with' => 'Carte invalide.',
        ];
    }
}
