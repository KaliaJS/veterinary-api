<?php

namespace App\Http\Requests\V1\Clients;

use App\Enums\Civilite;
use App\Models\PreferencesProprietaire;
use App\Rules\AdresseSansAccent;
use App\Rules\NumeroBdta;
use App\Services\Clients\OwnerPreferences;
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
            // L'unité d'élevage d'un détenteur d'animaux de rente (SI ABV, 08.11.2026)
            'holding_number' => ['sometimes', 'nullable', 'string', 'max:32', new NumeroBdta],
            'holding_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Case cochée par la clinique à la demande d'un client sans compte (28.10.2026)
            'rappels_sms' => ['sometimes', 'boolean'],
            // Fenêtre propriétaire (03.11.2026) : contact d'urgence, note de l'accueil,
            // rappels et préférences (client sans compte), accord photos
            'rappels_email' => ['sometimes', 'boolean'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'muted_reminders' => ['sometimes', 'nullable', 'array'],
            'muted_reminders.*' => [Rule::in(OwnerPreferences::KINDS)],
            'language' => ['sometimes', Rule::in(PreferencesProprietaire::LANGUES)],
            'accepts_news' => ['sometimes', 'boolean'],
            'photo_consent' => ['sometimes', 'boolean'],
        ];
    }
}
