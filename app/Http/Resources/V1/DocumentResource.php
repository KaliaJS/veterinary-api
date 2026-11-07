<?php

namespace App\Http\Resources\V1;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le chemin du fichier stocké reste interne : `a_fichier` dit s'il se
 * télécharge (`GET …/documents/{document}/fichier`).
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
            // Qui l'a ajouté au dossier (06.11.2026) ; null : hors de toute clinique, ou avant
            'employe_id' => $this->employe_id,
            'auteur' => $this->whenLoaded('employe', fn (): ?string => $this->employe?->nom),
            'etabli_le' => $this->etabli_le->toDateString(),
            'a_fichier' => $this->chemin !== null,
            'nom_fichier' => $this->nom_fichier,
            'taille_octets' => $this->taille_octets,
            'visible_proprietaire' => $this->visible_proprietaire,
        ];
    }
}
