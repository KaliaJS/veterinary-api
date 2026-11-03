<?php

namespace App\Http\Resources\V1;

use App\Models\SejourSoin;
use App\Services\Hospitalisation\StaySheets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SejourSoin
 */
class SejourSoinResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sejour_id' => $this->sejour_id,
            'jour' => $this->jour->toDateString(),
            // Le jour de la feuille (06:00 à 06:00) : 02:00 du mercredi est sur celle du mardi
            'sheet_day' => StaySheets::sheetDayOf($this->jour->toDateString(), $this->prevu_a),
            'prevu_a' => $this->prevu_a,
            'acte' => $this->acte,
            'produit_id' => $this->produit_id,
            'fait' => $this->fait,
            'fait_le' => $this->fait_le,
            // La ronde (03.11.2026) : administration d'un traitement, non donné, auteur figé
            'treatment_id' => $this->treatment_id,
            'dose' => $this->dose,
            'done_by' => $this->done_by_name,
            'skipped_at' => $this->skipped_at,
            'skipped_by' => $this->skipped_by_name,
            'skip_reason' => $this->skip_reason,
            'produit' => $this->whenLoaded('produit', fn (): ?array => $this->produit === null ? null : [
                'id' => $this->produit->id,
                'nom' => $this->produit->nom,
            ]),
        ];
    }
}
