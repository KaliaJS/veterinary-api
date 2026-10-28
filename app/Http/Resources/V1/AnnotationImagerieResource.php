<?php

namespace App\Http\Resources\V1;

use App\Models\AnnotationImagerie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnnotationImagerie */
class AnnotationImagerieResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliche_id' => $this->cliche_dicom_id,
            'type' => $this->type,
            'points' => $this->points,
            'image' => $this->image,
            'etiquette' => $this->etiquette,
            'certitude' => $this->certitude,
            'note' => $this->note,
            'auteur' => $this->auteur_id === null ? null : ['id' => $this->auteur_id, 'nom' => $this->auteur?->name],
            'cree_le' => $this->created_at?->toIso8601String(),
            'modifie_le' => $this->updated_at?->toIso8601String(),
            'version_schema' => $this->version_schema,
        ];
    }
}
