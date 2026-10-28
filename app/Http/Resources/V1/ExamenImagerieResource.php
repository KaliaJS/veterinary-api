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
            // Avec l'original envoyé, l'espèce du jour, région et incidence (28.10.2026)
            'cliches' => $this->whenLoaded('cliches', fn () => $this->cliches->map(fn (ClicheDicom $cliche): array => (new ClicheDicomResource($cliche))->resolve())),
            'confirmations' => $this->whenLoaded('confirmations', fn () => ConfirmationImagerieResource::collection($this->confirmations)->resolve()),
        ];
    }
}
