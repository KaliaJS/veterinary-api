<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Models\NeuroExam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un examen neurologique, ses formes toujours complètes ; `is_editable`
 * dit si cette clinique peut encore le modifier (le jour même, dans
 * l'organisation qui l'a fait). Les signes seulement : aucune localisation.
 *
 * @mixin NeuroExam
 */
class NeuroExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day' => $this->day->toDateString(),
            'by_name' => $this->by_name,
            'is_editable' => $this->isEditableBy(app(ContexteClinique::class)->organisationId()),
            'mental_status' => $this->mental_status,
            'gait_score' => $this->gait_score,
            'limbs' => NeuroExam::normalizeLimbs($this->limbs),
            'spine' => NeuroExam::normalizeSpine($this->spine),
            'cranial_nerves' => NeuroExam::normalizeCranialNerves($this->cranial_nerves),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
