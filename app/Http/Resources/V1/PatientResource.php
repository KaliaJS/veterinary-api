<?php

namespace App\Http\Resources\V1;

use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReleveDentaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Les alertes sont dérivées (`agressif`, `allergies`, `solde` si dette)
 * comme au front — jamais stockées. Les sous-collections du dossier ne
 * sortent que chargées (show) ; l'odontogramme est rendu en map
 * « n° Triadan → état », l'absence de clé vaut sain. La liste porte
 * le dernier poids, la courbe des douze dernières pesées (sparkline)
 * et le prochain rendez-vous, toutes cliniques de l'organisation.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'proprietaire_id' => $this->proprietaire_id,
            'nom' => $this->nom,
            'espece' => $this->espece,
            'race' => $this->race,
            'sexe' => $this->sexe,
            'ne_le' => $this->ne_le?->toDateString(),
            'est_sterilise' => $this->est_sterilise,
            'puce' => $this->puce,
            'dette' => (float) $this->dette,
            'alertes' => $this->alertes(),
            'est_agressif' => $this->est_agressif,
            'a_allergies' => $this->a_allergies,
            'traitement_en_cours' => $this->traitement_en_cours,
            'rappels_coupes' => $this->rappels_coupes,
            'amicus_declare_le' => $this->amicus_declare_le,
            'client_depuis' => $this->client_depuis,
            'derniere_visite_le' => $this->whenHas('visites_max_visite_le'),
            'echeance_vaccinale' => $this->whenHas('vaccinations_min_echeance_le'),
            'dernier_poids_kg' => $this->whenLoaded('courbePoids', fn (): ?float => $this->courbePoids->first()?->poids_kg === null
                ? null
                : (float) $this->courbePoids->first()->poids_kg),
            'courbe_poids' => $this->whenLoaded('courbePoids', fn (): array => $this->courbePoids
                ->reverse()
                ->map(fn (Pesee $pesee): float => (float) $pesee->poids_kg)
                ->values()
                ->all()),
            'prochain_rendez_vous' => $this->whenLoaded('rendezVousAVenir', function (): ?array {
                $rendezVous = $this->rendezVousAVenir->first();

                return $rendezVous === null ? null : [
                    'id' => $rendezVous->id,
                    'clinique_id' => $rendezVous->clinique_id,
                    'ligne_planning_id' => $rendezVous->ligne_planning_id,
                    'type' => $rendezVous->type,
                    'status' => $rendezVous->status,
                    'reason' => $rendezVous->reason,
                    'starts_at' => $rendezVous->starts_at,
                    'ends_at' => $rendezVous->ends_at,
                ];
            }),
            'client' => new ClientResource($this->whenLoaded('client')),
            'pesees' => PeseeResource::collection($this->whenLoaded('pesees')),
            'vaccinations' => VaccinationResource::collection($this->whenLoaded('vaccinations')),
            'visites' => VisiteResource::collection($this->whenLoaded('visites')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'releves_ophtalmologiques' => ReleveOphtalmologiqueResource::collection($this->whenLoaded('relevesOphtalmologiques')),
            'dents' => $this->whenLoaded(
                'relevesDentaires',
                fn () => $this->relevesDentaires
                    ->mapWithKeys(fn (ReleveDentaire $releve) => [(string) $releve->dent => $releve->etat])
                    ->toArray(),
            ),
        ];
    }

    /** @return list<string> */
    private function alertes(): array
    {
        return array_values(array_filter([
            $this->est_agressif ? 'agressif' : null,
            $this->a_allergies ? 'allergies' : null,
            $this->aSoldeDu() ? 'solde' : null,
        ]));
    }
}
