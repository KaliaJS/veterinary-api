<?php

namespace App\Http\Requests\V1\Facturation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Arrêté de caisse : fond initial, espèces comptées, confirmations de
 * rapprochement TPE/TWINT, dépôt et nouveau fond. Les totaux par moyen
 * sont calculés côté serveur sur les encaissements non couverts — les
 * gardes métier (rapprochements, écart justifié) vivent au contrôleur.
 */
class EnregistrerClotureCaisseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fond' => ['required', 'numeric', 'min:0', 'max:99999'],
            'especes_comptees' => ['required', 'numeric', 'min:0', 'max:999999'],
            'tpe_rapproche' => ['sometimes', 'boolean'],
            'twint_rapproche' => ['sometimes', 'boolean'],
            'depot' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'nouveau_fond' => ['required', 'numeric', 'min:0', 'max:99999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
