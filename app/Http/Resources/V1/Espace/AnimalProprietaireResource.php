<?php

namespace App\Http\Resources\V1\Espace;

use App\Models\Patient;
use App\Models\Visite;
use App\Services\EtatVaccinal;
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
            'est_sterilise' => $this->est_sterilise,
            /* Droits : la fiche est gérée par ce compte (modifiable ici), et ce que la clinique peut en faire */
            'gere_par_moi' => $this->proprietaire_id !== null && $this->proprietaire_id === $request->user()?->id,
            'acces_clinique' => $this->acces_clinique?->value ?? 'modification',
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
     * Carnet : état par maladie couverte (EtatVaccinal) : dernière injection,
     * échéance, statut (`retard`, `primo`, `proche`, `ok`, `inconnu`),
     * produit. Les clés `id`, `nom`, `injectee_le`, `echeance_le`, `jours`,
     * `statut` restent celles du proto.
     *
     * @return list<array<string, mixed>>
     */
    private function carnet(): array
    {
        return array_map(fn (array $etat): array => [
            'id' => $etat['vaccination_id'],
            'valence' => $etat['valence'],
            'nom' => $etat['nom'],
            'nom_court' => $etat['nom_court'],
            'produit' => $etat['produit'],
            'lot' => $etat['lot'],
            'injectee_le' => $etat['injectee_le'],
            'echeance_le' => $etat['echeance_le'],
            'jours' => $etat['jours'],
            'statut' => $etat['statut'],
            'primo' => $etat['primo'],
            'injections' => $etat['injections'],
        ], EtatVaccinal::parValence($this->vaccinations));
    }
}
