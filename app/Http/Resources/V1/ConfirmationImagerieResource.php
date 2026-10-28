<?php

namespace App\Http\Resources\V1;

use App\Models\ConfirmationImagerie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConfirmationImagerie */
class ConfirmationImagerieResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'examen_id' => $this->examen_imagerie_id,
            'etiquette' => $this->etiquette,
            'resultat' => $this->resultat,
            'source' => $this->source,
            'constate_le' => $this->constate_le->toDateString(),
            'document_id' => $this->document_id,
            'note' => $this->note,
            'auteur' => $this->auteur_id === null ? null : ['id' => $this->auteur_id, 'nom' => $this->auteur?->name],
            'cree_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
