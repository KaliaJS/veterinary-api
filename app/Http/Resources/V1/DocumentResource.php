<?php

namespace App\Http\Resources\V1;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le chemin du fichier stocké reste interne — le téléchargement aura
 * son endpoint quand le stockage réel arrivera.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type,
            'signataire' => $this->signataire,
            'etabli_le' => $this->etabli_le->toDateString(),
        ];
    }
}
