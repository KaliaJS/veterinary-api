<?php

namespace App\Http\Requests\V1\Organisation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Carte de l'abonnement : seul le libellé masqué est conservé — le
 * numéro complet ne touche jamais la base (le PSP le garde).
 */
class EnregistrerMoyenPaiementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'titulaire' => ['required', 'string', 'max:255'],
            'numero' => ['required', 'string', 'regex:/^[\d\s]{15,23}$/'],
            'expiration' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
        ];
    }
}
