<?php

namespace App\Http\Resources\V1;

use App\Models\ClicheDicom;
use App\Models\ExamenImagerie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamenImagerie
 */
class ExamenImagerieResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'modalite' => $this->modalite,
            'libelle' => $this->libelle,
            'incidence' => $this->incidence,
            'realise_le' => $this->realise_le,
            'duree_minutes' => $this->duree_minutes,
            'est_signe' => $this->estSigne(),
            'constatations' => $this->constatations,
            'conclusion' => $this->conclusion,
            'signe_le' => $this->signe_le,
            'patient' => $this->whenLoaded('patient', fn (): array => [
                'id' => $this->patient->id,
                'nom' => $this->patient->nom,
                'espece' => $this->patient->espece,
                'proprietaire' => $this->patient->client->nom_affiche,
            ]),
            'prescripteur' => $this->whenLoaded('prescripteur', fn (): array => [
                'id' => $this->prescripteur->id,
                'nom' => $this->prescripteur->nom,
            ]),
            'signe_par' => $this->whenLoaded('signePar', fn (): ?array => $this->signePar === null ? null : [
                'id' => $this->signePar->id,
                'nom' => $this->signePar->nom,
            ]),
            'cliches' => $this->whenLoaded('cliches', fn () => $this->cliches->map(fn (ClicheDicom $cliche): array => [
                'id' => $cliche->id,
                'sop_instance_uid' => $cliche->sop_instance_uid,
                'study_instance_uid' => $cliche->study_instance_uid,
                'series_instance_uid' => $cliche->series_instance_uid,
                'nom_fichier' => $cliche->nom_fichier,
                'chemin' => $cliche->chemin,
                'metadonnees' => $cliche->metadonnees,
            ])),
        ];
    }
}
