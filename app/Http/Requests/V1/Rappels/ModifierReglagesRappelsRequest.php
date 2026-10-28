<?php

namespace App\Http\Requests\V1\Rappels;

use App\Services\Rappels\RappelsProprietaires;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Réglages « Rappels & messages » de la clinique (28.10.2026). */
class ModifierReglagesRappelsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'boolean'],
            // Aucun prestataire SMS branché pour l'instant
            'sms' => ['sometimes', 'boolean', 'declined'],
            'delai_jours' => ['sometimes', 'integer', Rule::in(RappelsProprietaires::DELAIS)],
            'vaccins' => ['sometimes', 'boolean'],
            'controles' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sms.declined' => 'Les rappels par SMS ne sont pas encore disponibles.',
            'delai_jours.in' => 'Choisissez 7 jours, 3 jours ou la veille.',
            'delai_jours.integer' => 'Choisissez 7 jours, 3 jours ou la veille.',
            '*.boolean' => 'Choisissez oui ou non.',
        ];
    }
}
