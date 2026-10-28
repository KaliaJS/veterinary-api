<?php

namespace App\Http\Resources\V1;

use App\Models\ClicheDicom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClicheDicom */
class ClicheDicomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'examen_id' => $this->examen_imagerie_id,
            'sop_instance_uid' => $this->sop_instance_uid,
            'study_instance_uid' => $this->study_instance_uid,
            'series_instance_uid' => $this->series_instance_uid,
            'nom_fichier' => $this->nom_fichier,
            'chemin' => $this->chemin,
            'metadonnees' => $this->metadonnees,
            // L'original envoyé, gardé tel quel (28.10.2026)
            'a_fichier' => $this->taille_octets !== null,
            'taille_octets' => $this->taille_octets,
            'syntaxe_transfert' => $this->syntaxe_transfert,
            'modalite_dicom' => $this->modalite_dicom,
            'lignes' => $this->lignes,
            'colonnes' => $this->colonnes,
            'images' => $this->images,
            'espece' => $this->espece,
            'race' => $this->race,
            'poids_kg' => $this->poids_kg,
            'region' => $this->region,
            'incidence' => $this->incidence,
            'reperes_dicom' => $this->reperes_dicom,
            'reperes_valides' => $this->reperes_valides_le !== null,
            'reperes_valides_le' => $this->reperes_valides_le?->toIso8601String(),
            'annotations' => $this->whenCounted('annotations'),
        ];
    }
}
