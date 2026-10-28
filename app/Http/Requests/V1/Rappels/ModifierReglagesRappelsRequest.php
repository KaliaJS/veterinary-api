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
            // Plus de choix d'e-mail ni de SMS pour la clinique (décision de Jim,
            // 28.10.2026) : envoyés par une ancienne version de l'app, ignorés
            'delai_jours' => ['sometimes', 'integer', Rule::in(RappelsProprietaires::DELAIS)],
            'vaccins' => ['sometimes', 'boolean'],
            'controles' => ['sometimes', 'boolean'],
            'relance_noshow' => ['sometimes', 'boolean'],
            'messages_recus' => ['sometimes', 'boolean'],
            'messages_reponses_seulement' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delai_jours.in' => 'Choisissez 7 jours, 3 jours ou la veille.',
            'delai_jours.integer' => 'Choisissez 7 jours, 3 jours ou la veille.',
            '*.boolean' => 'Choisissez oui ou non.',
        ];
    }
}
