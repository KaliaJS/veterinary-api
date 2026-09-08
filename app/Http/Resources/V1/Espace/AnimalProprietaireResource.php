<?php

namespace App\Http\Resources\V1\Espace;

use App\Models\Patient;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Services\AccueilProprietaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Animal vu par son propriétaire (liste et fiche du proto « Mes
 * animaux ») : identité, âge et dernier poids dérivés ici, fiche
 * client d'origine ; chargés à la demande : carnet de vaccination (la
 * dernière injection par vaccin, avec l'échéance et son statut),
 * historique des visites, ordonnance active.
 *
 * @mixin Patient
 */
class AnimalProprietaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'espece' => $this->espece?->value,
            'espece_libelle' => $this->espece?->libelle(),
            'race' => $this->race,
            'sexe' => $this->sexe?->value,
            'ne_le' => $this->ne_le?->toDateString(),
            'age_libelle' => self::age($this->ne_le),
            'puce' => $this->puce,
            'poids_kg' => $this->whenLoaded('courbePoids', fn (): ?float => $this->courbePoids->first()?->poids_kg === null
                ? null
                : (float) $this->courbePoids->first()->poids_kg),
            'fiche_id' => $this->client_id,
            'organisation' => $this->whenLoaded('organisation', fn (): array => ['id' => $this->organisation->id, 'nom' => $this->organisation->nom]),
            'vaccinations' => $this->whenLoaded('vaccinations', fn (): array => $this->carnet()),
            'visites' => $this->whenLoaded('visites', fn (): array => $this->visites
                ->sortByDesc('visite_le')
                ->values()
                ->map(fn (Visite $visite): array => [
                    'id' => $visite->id,
                    'visite_le' => $visite->visite_le->toDateString(),
                    'motif' => $visite->motif,
                    'veterinaire' => $visite->employe?->nom,
                ])
                ->all()),
            'ordonnance_active_id' => $this->when(isset($this->ordonnance_active_id), fn () => $this->ordonnance_active_id),
        ];
    }

    /** « 5 ans », « 8 mois », « 3 semaines » ; null sans date de naissance. */
    public static function age(?Carbon $neLe): ?string
    {
        if (! $neLe) {
            return null;
        }

        $ans = (int) $neLe->diffInYears(today());
        if ($ans >= 1) {
            return $ans.' an'.($ans > 1 ? 's' : '');
        }

        $mois = (int) $neLe->diffInMonths(today());
        if ($mois >= 1) {
            return $mois.' mois';
        }

        $semaines = max(1, (int) $neLe->diffInWeeks(today()));

        return $semaines.' semaine'.($semaines > 1 ? 's' : '');
    }

    /**
     * Carnet : dernière injection par vaccin, échéance et statut
     * (`retard` : dépassée ; `proche` : à J+30 ; `ok` : au-delà).
     *
     * @return list<array<string, mixed>>
     */
    private function carnet(): array
    {
        $aujourdhui = today();

        return $this->vaccinations
            ->sortByDesc('injectee_le')
            ->unique(fn (Vaccination $vaccination): string => mb_strtolower($vaccination->nom))
            ->sortBy(fn (Vaccination $vaccination) => $vaccination->echeance_le?->timestamp ?? PHP_INT_MAX)
            ->values()
            ->map(function (Vaccination $vaccination) use ($aujourdhui): array {
                $jours = $vaccination->echeance_le ? (int) $aujourdhui->diffInDays($vaccination->echeance_le, false) : null;

                return [
                    'id' => $vaccination->id,
                    'nom' => $vaccination->nom,
                    'injectee_le' => $vaccination->injectee_le?->toDateString(),
                    'echeance_le' => $vaccination->echeance_le?->toDateString(),
                    'jours' => $jours,
                    'statut' => $jours === null ? 'ok' : ($jours < 0 ? 'retard' : ($jours <= AccueilProprietaire::RAPPEL_JOURS ? 'proche' : 'ok')),
                ];
            })
            ->all();
    }
}
