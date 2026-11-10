<?php

namespace App\Http\Requests\V1\Preferences;

use App\Enums\ToolKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Préférences de la personne du jeton (04.11.2026) : seules les clés
 * d'outils connues, chacune une fois, dans l'ordre des ajouts.
 */
class UpdateStaffPreferencesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tool_favorites' => ['sometimes', 'present', 'array', 'max:'.count(ToolKey::cases())],
            'tool_favorites.*' => ['string', 'distinct', Rule::enum(ToolKey::class)],
            'hide_non_favorite_tools' => ['sometimes', 'boolean'],
            // Schéma dentaire (04.11.2026) : A fiche AVDC, B carte en couleur, C vue de côté, D quadrants
            'dental_chart_view' => ['sometimes', 'string', Rule::in(['A', 'B', 'C', 'D'])],
            // Statistiques de la Caisse (06.11.2026) : barres ou courbe
            'stats_chart_style' => ['sometimes', 'string', Rule::in(['bars', 'line'])],
            // La salle d'attente à gauche du planning (10.11.2026)
            'waiting_room' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tool_favorites.array' => 'La liste des favoris est illisible.',
            'tool_favorites.max' => 'Trop d’outils en favoris.',
            'tool_favorites.*.distinct' => 'Cet outil est déjà en favori.',
            'tool_favorites.*' => 'Cet outil n’existe pas.',
            'hide_non_favorite_tools.boolean' => 'Choisissez oui ou non.',
            'dental_chart_view' => 'Cette vue du schéma dentaire n’existe pas.',
            'stats_chart_style' => 'Choisissez les barres ou la courbe.',
            'waiting_room' => 'Choisissez oui ou non.',
        ];
    }
}
