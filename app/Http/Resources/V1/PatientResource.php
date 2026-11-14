<?php

namespace App\Http\Resources\V1;

use App\Enums\DomainePermission;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReferralInvitation;
use App\Models\ReleveDentaire;
use App\Services\Isabv\Declarations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Les alertes sont dérivées (`agressif`, `allergies`, `solde` si dette)
 * comme au front — jamais stockées. La dette et l'alerte « solde » ne
 * sortent qu'avec le droit de la facturation (audit C10, 14.11.2026). Les sous-collections du dossier ne
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
            // La robe et le vétérinaire référent (06.11.2026)
            'coat' => $this->coat,
            // SI ABV (08.11.2026) : choisie sur la fiche, sinon proposée selon l'espèce
            'isabv_species' => $this->isabv_species,
            'isabv_species_default' => Declarations::defaultSpecies($this->resource),
            'referring_vet_id' => $this->referring_vet_id,
            'referring_vet' => $this->whenLoaded('referringVet', fn (): ?array => $this->referringVet === null ? null : [
                'id' => $this->referringVet->id,
                'nom' => $this->referringVet->nom,
            ]),
            // Qui l'a référé, tant que l'accès est ouvert (Jim, 10.11.2026 : « quand c'est référé on ne voit pas que ça l'est ») ; fiche seulement
            'refere_par' => $this->whenLoaded('referencesRecues', fn (): array => $this->referencesRecues->map(fn (ReferralInvitation $i): array => [
                'invitation_id' => $i->id,
                'clinique' => $i->clinique?->nom,
                'ville' => $i->clinique?->ville,
                'par' => $i->sent_by_name,
                'le' => $i->accepted_at?->toIso8601String(),
            ])->values()->all()),
            'sexe' => $this->sexe,
            'ne_le' => $this->ne_le?->toDateString(),
            'decede_le' => $this->decede_le?->toDateString(),
            'est_sterilise' => $this->est_sterilise,
            'puce' => $this->puce,
            'assurance' => $this->assurance,
            'photo_url' => $this->photoUrl(),
            'dette' => $this->when(self::voitLaFacturation(), fn (): float => (float) $this->dette),
            'alertes' => $this->alertes(),
            'est_agressif' => $this->est_agressif,
            'a_allergies' => $this->a_allergies,
            // Les précisions des alertes (02.11.2026)
            'aggression_triggers' => ($this->aggression_triggers ?? collect())->map->value->values(),
            'needs_muzzle' => (bool) $this->needs_muzzle,
            'allergens' => ($this->allergens ?? collect())->map->value->values(),
            'allergy_note' => $this->allergy_note,
            'traitement_en_cours' => $this->traitement_en_cours,
            // Le poids de forme saisi par le vétérinaire (03.11.2026) : sans saisie, pas de bande
            'healthy_weight_min_kg' => $this->healthy_weight_min_kg !== null ? (float) $this->healthy_weight_min_kg : null,
            'healthy_weight_max_kg' => $this->healthy_weight_max_kg !== null ? (float) $this->healthy_weight_max_kg : null,
            // Où il en est, d'après sa dernière pesée : above, within, below ; null sans bande ou sans pesée
            'healthy_weight_position' => $this->whenLoaded('courbePoids', fn (): ?string => $this->healthyWeightPosition()),
            'rappels_coupes' => $this->rappels_coupes,
            'amicus_declare_le' => $this->amicus_declare_le,
            'client_depuis' => $this->client_depuis,
            /* Droits sur l animal : qui gère la fiche, et si la clinique peut en modifier l identité */
            'droits' => [
                'gere_par' => $this->estGereParProprietaire() ? 'proprietaire' : 'clinique',
                'acces_clinique' => $this->acces_clinique->value,
                'modifier_identite' => $this->cliniquePeutModifierIdentite(),
            ],
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
                    ->sortBy('dent')
                    ->mapWithKeys(fn (ReleveDentaire $releve) => [(string) $releve->dent => $releve->pourSchema()])
                    // Un objet : la ressource renumérote un tableau aux clés numériques
                    ->pipe(fn ($dents) => (object) $dents->all()),
            ),
        ];
    }

    /** @return list<string> */
    private function alertes(): array
    {
        return array_values(array_filter([
            $this->est_agressif ? 'agressif' : null,
            $this->a_allergies ? 'allergies' : null,
            self::voitLaFacturation() && $this->aSoldeDu() ? 'solde' : null,
        ]));
    }

    /** La dette et son alerte : le droit de la facturation (audit C10) */
    public static function voitLaFacturation(): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    /** La dernière pesée face au poids de forme saisi (03.11.2026) */
    private function healthyWeightPosition(): ?string
    {
        $last = $this->courbePoids->first()?->poids_kg;
        if ($last === null || $this->healthy_weight_min_kg === null || $this->healthy_weight_max_kg === null) {
            return null;
        }

        return match (true) {
            (float) $last > (float) $this->healthy_weight_max_kg => 'above',
            (float) $last < (float) $this->healthy_weight_min_kg => 'below',
            default => 'within',
        };
    }
}
