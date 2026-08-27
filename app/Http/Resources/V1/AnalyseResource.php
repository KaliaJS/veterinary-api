<?php

namespace App\Http\Resources\V1;

use App\Models\Analyse;
use App\Models\AnalysePanel;
use App\Models\AnalyseResultat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Analyse
 */
class AnalyseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'statut' => $this->statut,
            'priorite' => $this->priorite,
            'preleve_le' => $this->preleve_le,
            'note' => $this->note,
            'valide_le' => $this->valide_le,
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
            'valide_par' => $this->whenLoaded('validePar', fn (): ?array => $this->validePar === null ? null : [
                'id' => $this->validePar->id,
                'nom' => $this->validePar->nom,
            ]),
            'panels' => $this->whenLoaded('panels', fn () => $this->panels->map(fn (AnalysePanel $panel): array => [
                'code' => $panel->code,
                'nom' => $panel->nom,
                'tube' => $panel->tube,
            ])),
            'resultats' => $this->whenLoaded('resultats', fn () => $this->resultats->map(fn (AnalyseResultat $resultat): array => [
                'id' => $resultat->id,
                'panel' => $resultat->panel->code,
                'parametre' => $resultat->parametre,
                'unite' => $resultat->unite,
                'borne_basse' => $resultat->borne_basse,
                'borne_haute' => $resultat->borne_haute,
                'valeur' => $resultat->valeur,
                'drapeau' => $resultat->drapeau,
            ])),
        ];
    }
}
