<?php

namespace App\Http\Resources\V1;

use App\Models\SurgeryReport;
use App\Models\SurgeryReportComplement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le rapport de bloc (09.11.2026) : son état, le brouillon de l'IA (sources
 * numérotées, phrases et leurs sources, doutes), le texte relu par
 * rubrique, la validation et les compléments. Toutes les rubriques sont
 * toujours là, vides comprises (à compléter).
 *
 * @mixin SurgeryReport
 */
class SurgeryReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'intervention_id' => $this->intervention_id,
            'status' => $this->status,
            'error' => $this->error,
            'is_validated' => $this->isValidated(),
            'writings' => $this->writings,
            'writings_max' => (int) config('transcription.redactions_max'),
            'draft' => $this->draft,
            'sections' => SurgeryReport::normalizeSections($this->sections),
            'section_labels' => SurgeryReport::SECTION_LABELS,
            'written_at' => $this->written_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by_name' => $this->validated_by_name,
            'complements' => $this->complements->map(fn (SurgeryReportComplement $c) => [
                'id' => $c->id,
                'text' => $c->text,
                'by_name' => $c->by_name,
                'at' => $c->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
