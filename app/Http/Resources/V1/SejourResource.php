<?php

namespace App\Http\Resources\V1;

use App\Enums\PainScale;
use App\Http\Controllers\Api\V1\StayTreatmentController;
use App\Models\Sejour;
use App\Models\StayTreatment;
use App\Services\Hospitalisation\StaySheets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sejour
 */
class SejourResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            // Le propriétaire qui a confié l'animal, figé à l'admission (03.11.2026)
            'client_id' => $this->client_id,
            'admitted_by' => $this->admitted_by_name,
            'admission_owner' => $this->whenLoaded('client', fn (): ?string => $this->client?->nom_affiche),
            'box' => $this->box,
            'motif' => $this->motif,
            'statut' => $this->statut,
            'veterinaire_id' => $this->veterinaire_id,
            'admis_le' => $this->admis_le,
            'sortie_prevue' => $this->sortie_prevue,
            'sorti_le' => $this->sorti_le,
            'perfusion' => $this->perfusion,
            'note' => $this->note,
            'facture_id' => $this->facture_id,
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
                'sexe' => $this->patient->sexe,
            ]),
            'veterinaire' => $this->whenLoaded('veterinaire', fn (): ?array => $this->veterinaire === null ? null : [
                'id' => $this->veterinaire->id,
                'nom' => $this->veterinaire->nom,
            ]),
            // La ronde (03.11.2026) : le jour du séjour, servi, jamais recalculé
            'day_number' => app(StaySheets::class)->dayNumber($this->resource),
            'since_label' => app(StaySheets::class)->sinceLabel($this->resource),
            'admitted_time' => app(StaySheets::class)->time($this->resource, $this->admis_le),
            'today_sheet' => app(StaySheets::class)->today($this->resource),
            'pain_scale' => $this->whenLoaded('patient', fn (): array => PainScale::forSpecies($this->patient->espece)->toArray()),
            'treatments' => $this->whenLoaded('treatments', fn (): array => $this->treatments->map(fn (StayTreatment $t): array => StayTreatmentController::data($t))->values()->all()),
            'soins' => SejourSoinResource::collection($this->whenLoaded('soins')),
            'constantes' => SejourConstanteResource::collection($this->whenLoaded('constantes')),
        ];
    }
}
