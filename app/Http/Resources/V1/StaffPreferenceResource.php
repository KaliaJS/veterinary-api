<?php

namespace App\Http\Resources\V1;

use App\Models\StaffPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffPreference */
class StaffPreferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tool_favorites' => array_values($this->tool_favorites ?? []),
            'hide_non_favorite_tools' => (bool) $this->hide_non_favorite_tools,
            // La vue du schéma dentaire (04.11.2026), seulement une fois choisie
            'dental_chart_view' => $this->when($this->dental_chart_view !== null, fn () => $this->dental_chart_view),
            // Le graphique des statistiques de la Caisse (06.11.2026), seulement une fois choisi
            'stats_chart_style' => $this->when($this->stats_chart_style !== null, fn () => $this->stats_chart_style),
        ];
    }
}
