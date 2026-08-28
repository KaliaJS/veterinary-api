<?php

namespace App\Http\Resources\V1;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Les champs agrégés (nombre d'animaux, solde dû = somme des dettes,
 * no-shows, client depuis = min des fiches) ne sortent que lorsque le
 * contrôleur les a chargés — jamais recalculés ici.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'civilite' => $this->civilite,
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'nom_affiche' => $this->nom_affiche,
            'tel' => $this->tel,
            'email' => $this->email,
            'rue' => $this->rue,
            'npa' => $this->npa,
            'localite' => $this->localite,
            'a_un_compte' => $this->user_id !== null,
            'nombre_patients' => $this->whenHas('patients_count'),
            'noms_patients' => $this->when(
                $this->relationLoaded('patientsParNom') || $this->relationLoaded('patients'),
                fn (): array => ($this->relationLoaded('patientsParNom') ? $this->patientsParNom : $this->patients->sortBy('nom'))
                    ->pluck('nom')
                    ->values()
                    ->all(),
            ),
            'solde_du' => $this->whenHas('patients_sum_dette', fn () => (float) ($this->patients_sum_dette ?? 0)),
            'no_shows' => $this->whenHas('no_shows_count'),
            'client_depuis' => $this->whenHas('patients_min_client_depuis'),
            'patients' => PatientResource::collection($this->whenLoaded('patients')),
        ];
    }
}
