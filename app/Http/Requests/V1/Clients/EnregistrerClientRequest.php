<?php

namespace App\Http\Requests\V1\Clients;

use App\Enums\Civilite;
use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La civilité est facultative : le formulaire du front ne la demande
 * pas pour un propriétaire créé à la main (docs/data-model.md §5).
 */
class EnregistrerClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'civilite' => ['nullable', Rule::enum(Civilite::class)],
            'prenom' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'tel' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', new AdresseSansAccent],
            'rue' => ['nullable', 'string', 'max:255'],
            'npa' => ['nullable', 'digits:4'],
            'localite' => ['nullable', 'string', 'max:255'],
            // Case cochée par la clinique à la demande du client (28.10.2026)
            'rappels_sms' => ['sometimes', 'boolean'],
        ];
    }
}
