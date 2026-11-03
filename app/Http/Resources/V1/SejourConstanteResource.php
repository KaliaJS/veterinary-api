<?php

namespace App\Http\Resources\V1;

use App\Models\SejourConstante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SejourConstante
 */
class SejourConstanteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sejour_id' => $this->sejour_id,
            'jour' => $this->jour->toDateString(),
            'mesure_a' => $this->mesure_a,
            'temperature' => $this->temperature,
            'fc' => $this->fc,
            'fr' => $this->fr,
            // Qui l'a relevé, et s'il a été noté après coup (03.11.2026)
            'by' => $this->by_name,
            'is_late_note' => $this->late_noted_at !== null,
        ];
    }
}
