<?php

namespace App\Http\Requests\V1\Espace;

use App\Models\PreferencesProprietaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierPreferencesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rappels_email' => ['sometimes', 'boolean'],
            'rappels_sms' => ['sometimes', 'boolean'],
            'reponses_clinique' => ['sometimes', 'boolean'],
            'nouveautes_boutique' => ['sometimes', 'boolean'],
            'statistiques_anonymes' => ['sometimes', 'boolean'],
            'langue' => ['sometimes', Rule::in(PreferencesProprietaire::LANGUES)],
        ];
    }
}
