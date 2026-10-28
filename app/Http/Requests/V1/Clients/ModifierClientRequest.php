<?php

namespace App\Http\Requests\V1\Clients;

use App\Enums\Civilite;
use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'civilite' => ['sometimes', 'nullable', Rule::enum(Civilite::class)],
            'prenom' => ['sometimes', 'string', 'max:255'],
            'nom' => ['sometimes', 'string', 'max:255'],
            'tel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', new AdresseSansAccent],
            'rue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'npa' => ['sometimes', 'nullable', 'digits:4'],
            'localite' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Case cochée par la clinique à la demande d'un client sans compte (28.10.2026)
            'rappels_sms' => ['sometimes', 'boolean'],
        ];
    }
}
