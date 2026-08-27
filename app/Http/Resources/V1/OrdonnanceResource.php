<?php

namespace App\Http\Resources\V1;

use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le signataire sort par son nom (l'employé peut être d'une clinique
 * sœur — son scope est levé au chargement, comme pour le dossier
 * médical).
 *
 * @mixin Ordonnance
 */
class OrdonnanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'employe_id' => $this->employe_id,
            'signataire' => $this->whenLoaded('employe', fn (): string => $this->employe->nom),
            'signee_le' => $this->signee_le->toDateString(),
            'renouvellements' => $this->renouvellements,
            'statut' => $this->statut,
            'note' => $this->note,
            'ordonnance_precedente_id' => $this->ordonnance_precedente_id,
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (OrdonnanceLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->relationLoaded('produit') ? $ligne->produit->nom : null,
                    'posologie' => $ligne->posologie,
                    'duree' => $ligne->duree,
                ])->values(),
            ),
            'patient' => new PatientResource($this->whenLoaded('patient')),
        ];
    }
}
