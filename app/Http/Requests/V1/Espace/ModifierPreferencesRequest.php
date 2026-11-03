<?php

namespace App\Http\Requests\V1\Espace;

use App\Models\PreferencesProprietaire;
use App\Services\Clients\OwnerPreferences;
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
            // Les types de rappels qu'il coupe (03.11.2026)
            'muted_reminders' => ['sometimes', 'nullable', 'array'],
            'muted_reminders.*' => [Rule::in(OwnerPreferences::KINDS)],
        ];
    }
}
