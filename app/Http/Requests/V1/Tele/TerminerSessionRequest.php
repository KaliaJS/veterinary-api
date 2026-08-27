<?php

namespace App\Http\Requests\V1\Tele;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fin d'appel (teleEnd) : `cloturer` distingue « Terminer » (clôt le
 * RDV lié — fini + facture) de « Quitter » (le rendez-vous reste en
 * cours). La durée n'est jamais acceptée du client — le serveur fait
 * foi.
 */
class TerminerSessionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cloturer' => ['required', 'boolean'],
            'sans_facture' => ['sometimes', 'boolean'],
        ];
    }
}
