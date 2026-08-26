<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Injection du jour » du carnet : sans date, l'injection est
 * d'aujourd'hui. L'échéance est explicite (le front la calcule du
 * catalogue — 1 an, rage 3 ans) ; le vétérinaire signataire est
 * l'employé du jeton.
 */
class EnregistrerVaccinationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // `after:injectee_le` a besoin d'une valeur — le défaut du
        // carnet est l'injection du jour.
        $this->mergeIfMissing(['injectee_le' => today()->toDateString()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'marque' => ['nullable', 'string', 'max:255'],
            'lot' => ['nullable', 'string', 'max:255'],
            'injectee_le' => ['sometimes', 'date', 'before_or_equal:today'],
            'echeance_le' => ['required', 'date', 'after:injectee_le'],
        ];
    }
}
