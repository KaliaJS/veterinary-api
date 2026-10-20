<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\StatutRendezVous;
use App\Enums\TypeAbsence;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Créneaux libres et bornes de journée (zones « FERMÉ ») calculés
 * depuis les horaires de la clinique — jamais des constantes. Les
 * horaires sont des heures LOCALES (fuseau de la clinique, défaut
 * Europe/Zurich) : le calcul se fait en minutes de la journée locale,
 * puis les créneaux sont rendus en datetimes UTC comme les
 * rendez-vous.
 *
 * Parité front (freeGaps / nextFree, stores/planning.js) : trous
 * ≥ 15 min entre rendez-vous, tampons non occupants, annulés exclus,
 * pause déjeuner comptée occupée sur toutes les lignes, et — pour
 * aujourd'hui — à partir de max(ouverture, maintenant). Les lignes sous
 * absence bloquante sont écartées (la création y est refusée).
 */
class CalculateurCreneaux
{
    public const int TROU_MINIMAL_MINUTES = 15;

    /** Horaires de la clinique du contexte, créés aux défauts au besoin. */
    public function horaires(): HoraireOuverture
    {
        return HoraireOuverture::firstOrCreate([], HoraireOuverture::DEFAUTS);
    }

    /** Fuseau de la clinique du contexte. */
    public function fuseau(): string
    {
        return app(ContexteClinique::class)->clinique()?->fuseau_horaire ?? 'Europe/Zurich';
    }

    /** Le jour calendaire courant, vu de la clinique. */
    public function aujourdHui(): CarbonImmutable
    {
        return CarbonImmutable::today($this->fuseau());
    }

    /**
     * Bornes effectives d'un jour donné, en minutes locales depuis
     * minuit — les zones fermées de la grille en découlent : avant
     * l'ouverture, après la fermeture, la pause.
     *
     * @return array{fermee: bool, ouverture: int, fermeture: int, pause: array{int, int}|null}
     */
    public function bornesDuJour(HoraireOuverture $horaires, CarbonImmutable $jour): array
    {
        $ouverture = $this->enMinutes($horaires->ouverture);
        $fermeture = $this->enMinutes($horaires->fermeture);
        $fermee = false;

        if ($jour->isSunday()) {
            $fermee = $horaires->dimanche_ferme;
        } elseif ($jour->isSaturday()) {
            $fermee = $horaires->samedi_ferme;

            if ($horaires->fermeture_samedi !== null) {
                $fermeture = $this->enMinutes($horaires->fermeture_samedi);
            }
        }

        $fermee = $fermee || $fermeture <= $ouverture;

        $pause = null;

        if (! $fermee && $horaires->pause_active && $horaires->pause_debut !== null && $horaires->pause_fin !== null) {
            $debut = $this->enMinutes($horaires->pause_debut);
            $fin = $this->enMinutes($horaires->pause_fin);

            // Parité front : la pause ne compte que si la fermeture la dépasse.
            if ($fermeture > $fin) {
                $pause = [$debut, $fin];
            }
        }

        return ['fermee' => $fermee, 'ouverture' => $ouverture, 'fermeture' => $fermeture, 'pause' => $pause];
    }

    /**
     * Trous libres d'un jour calendaire (vu de la clinique), par ligne,
     * triés par début. Chaque créneau est l'intervalle complet du trou,
     * en datetimes UTC.
     *
     * @param  CarbonImmutable  $jour  seul le jour calendaire compte
     * @return Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>
     */
    public function creneauxLibres(CarbonImmutable $jour, int $dureeMinutes = self::TROU_MINIMAL_MINUTES, ?int $lignePlanningId = null): Collection
    {
        return $this->creneauxDesJours($jour, 1, $dureeMinutes, $lignePlanningId)[0];
    }

    /**
     * Les trous libres de plusieurs jours consécutifs, un élément par
     * jour : le calcul de creneauxLibres, mais horaires, lignes,
     * absences et rendez-vous sont lus une seule fois pour toute la
     * période (l'annuaire explore une semaine par clinique).
     *
     * @return list<Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>>
     */
    public function creneauxDesJours(CarbonImmutable $premierJour, int $nombreJours, int $dureeMinutes = self::TROU_MINIMAL_MINUTES, ?int $lignePlanningId = null): array
    {
        $fuseau = $this->fuseau();
        $horaires = $this->horaires();

        $minuits = $this->minuits($premierJour, $nombreJours, $fuseau);

        $lignes = LignePlanning::query()
            ->when($lignePlanningId !== null, fn ($requete) => $requete->whereKey($lignePlanningId))
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        $absences = Absence::query()
            ->whereIn('employe_id', $lignes->pluck('employe_id')->filter()->unique()->values())
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $minuits[$nombreJours - 1]->toDateString())
            ->whereDate('fin_le', '>=', $minuits[0]->toDateString())
            ->get();

        $rendezVous = RendezVous::query()
            ->whereIn('ligne_planning_id', $lignes->pluck('id'))
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where('type', '!=', TypeRendezVous::Tampon)
            ->where('starts_at', '<', $minuits[$nombreJours]->utc())
            ->where('ends_at', '>', $minuits[0]->utc())
            ->get();

        return $this->calculerPeriode($horaires, $minuits, $fuseau, $dureeMinutes, $lignes, $absences, $rendezVous);
    }

    /**
     * Les trous libres de plusieurs cliniques à la fois, hors de tout
     * contexte (annuaire des cliniques) : horaires, lignes, absences et
     * rendez-vous de toutes les cliniques sont lus en quatre requêtes,
     * quel que soit leur nombre. Chaque clinique part de son propre
     * aujourd'hui, dans son fuseau ; sans horaires enregistrés, ce sont
     * les horaires par défaut.
     *
     * @param  Collection<int, Clinique>  $cliniques
     * @return array<int, list<Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>>> par id de clinique, un élément par jour
     */
    public function creneauxDesCliniques(Collection $cliniques, int $nombreJours, int $dureeMinutes = self::TROU_MINIMAL_MINUTES): array
    {
        if ($cliniques->isEmpty()) {
            return [];
        }

        $ids = $cliniques->modelKeys();
        $fuseaux = $cliniques->mapWithKeys(fn (Clinique $clinique): array => [$clinique->id => $clinique->fuseau_horaire ?? 'Europe/Zurich']);
        $minuits = $fuseaux->map(fn (string $fuseau): array => $this->minuits(CarbonImmutable::today($fuseau), $nombreJours, $fuseau));

        // Période commune : du premier minuit au dernier, tous fuseaux confondus.
        $debut = $minuits->map(fn (array $m): CarbonImmutable => $m[0])->min();
        $fin = $minuits->map(fn (array $m): CarbonImmutable => $m[$nombreJours])->max();

        $horaires = HoraireOuverture::withoutGlobalScope('clinique')->whereIn('clinique_id', $ids)->get()->keyBy('clinique_id');

        $lignes = LignePlanning::withoutGlobalScope('clinique')
            ->whereIn('clinique_id', $ids)
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        $absences = Absence::withoutGlobalScope('clinique')
            ->whereIn('employe_id', $lignes->pluck('employe_id')->filter()->unique()->values())
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $fin->subDay()->toDateString())
            ->whereDate('fin_le', '>=', $debut->toDateString())
            ->get();

        $rendezVous = RendezVous::withoutGlobalScope('clinique')
            ->whereIn('ligne_planning_id', $lignes->modelKeys())
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where('type', '!=', TypeRendezVous::Tampon)
            ->where('starts_at', '<', $fin->utc())
            ->where('ends_at', '>', $debut->utc())
            ->get(['id', 'clinique_id', 'ligne_planning_id', 'starts_at', 'ends_at'])
            ->groupBy('clinique_id');

        $lignes = $lignes->groupBy('clinique_id');

        return $cliniques->mapWithKeys(fn (Clinique $clinique): array => [$clinique->id => $this->calculerPeriode(
            $horaires->get($clinique->id) ?? new HoraireOuverture(HoraireOuverture::DEFAUTS),
            $minuits[$clinique->id],
            $fuseaux[$clinique->id],
            $dureeMinutes,
            $lignes->get($clinique->id, collect()),
            $absences,
            $rendezVous->get($clinique->id, collect()),
        )])->all();
    }

    /**
     * Premier créneau libre du jour pouvant accueillir la durée, toutes
     * lignes confondues.
     *
     * @return array{ligne_planning_id: int, debut: CarbonImmutable}|null
     */
    public function prochainCreneau(CarbonImmutable $jour, int $dureeMinutes): ?array
    {
        $creneau = $this->creneauxLibres($jour, $dureeMinutes)->first();

        return $creneau === null
            ? null
            : ['ligne_planning_id' => $creneau['ligne_planning_id'], 'debut' => $creneau['debut']];
    }

    /**
     * Une absence validée hors garde bloque la colonne du praticien —
     * l'instant (UTC) est ramené au jour calendaire de la clinique.
     */
    public function ligneEstBloquee(LignePlanning $ligne, CarbonImmutable $instant): bool
    {
        $jourLocal = $instant->setTimezone($this->fuseau())->toDateString();

        return $ligne->employe_id !== null && Absence::query()
            ->where('employe_id', $ligne->employe_id)
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $jourLocal)
            ->whereDate('fin_le', '>=', $jourLocal)
            ->exists();
    }

    /**
     * Minuit local de chaque jour, et du lendemain du dernier pour borner
     * la période. Reparsés par date calendaire : un addDay d'horloge
     * dériverait au changement d'heure.
     *
     * @return list<CarbonImmutable>
     */
    private function minuits(CarbonImmutable $premierJour, int $nombreJours, string $fuseau): array
    {
        return array_map(
            fn (int $i): CarbonImmutable => CarbonImmutable::parse($premierJour->addDays($i)->toDateString(), $fuseau),
            range(0, $nombreJours),
        );
    }

    /**
     * @param  list<CarbonImmutable>  $minuits
     * @param  Collection<int, LignePlanning>  $lignes
     * @param  Collection<int, Absence>  $absences
     * @param  Collection<int, RendezVous>  $rendezVous
     * @return list<Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>>
     */
    private function calculerPeriode(HoraireOuverture $horaires, array $minuits, string $fuseau, int $dureeMinutes, Collection $lignes, Collection $absences, Collection $rendezVous): array
    {
        // Chaque rendez-vous lu une seule fois : Eloquent recrée la date à
        // chaque accès, et l'annuaire en parcourt des milliers.
        $occupations = $rendezVous
            ->map(fn (RendezVous $rendezVous): array => [(int) $rendezVous->ligne_planning_id, $rendezVous->starts_at->getTimestamp(), $rendezVous->ends_at->getTimestamp()])
            ->all();

        $jours = [];

        for ($i = 0; $i < count($minuits) - 1; $i++) {
            $jours[] = $this->trousDuJour($horaires, $minuits[$i], $minuits[$i + 1], $fuseau, $dureeMinutes, $lignes, $absences, $occupations);
        }

        return $jours;
    }

    /**
     * Le calcul d'un jour, sur les lignes, absences et rendez-vous déjà
     * lus pour la période.
     *
     * @param  Collection<int, LignePlanning>  $lignes
     * @param  Collection<int, Absence>  $absences
     * @param  list<array{int, int, int}>  $occupations  [ligne, début, fin] en horodatages
     * @return Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>
     */
    private function trousDuJour(HoraireOuverture $horaires, CarbonImmutable $minuitLocal, CarbonImmutable $minuitSuivant, string $fuseau, int $dureeMinutes, Collection $lignes, Collection $absences, array $occupations): Collection
    {
        $bornes = $this->bornesDuJour($horaires, $minuitLocal);

        if ($bornes['fermee']) {
            return collect();
        }

        $date = $minuitLocal->toDateString();
        $absents = $absences
            ->filter(fn (Absence $absence): bool => $absence->debut_le->toDateString() <= $date && $absence->fin_le->toDateString() >= $date)
            ->pluck('employe_id');

        $debutJour = $minuitLocal->getTimestamp();
        $finJour = $minuitSuivant->getTimestamp();
        // Sans changement d'heure dans la journée, l'heure locale se déduit
        // de l'écart à minuit ; sinon on repasse par le fuseau.
        $journeeReguliere = $minuitLocal->getOffset() === $minuitSuivant->getOffset();

        $occupes = [];

        foreach ($occupations as [$ligneId, $debut, $fin]) {
            if ($debut < $finJour && $fin > $debutJour) {
                $occupes[$ligneId][] = [$debut, $fin];
            }
        }

        $depart = $bornes['ouverture'];
        $maintenantLocal = now()->setTimezone($fuseau);

        if ($maintenantLocal->toDateString() === $date) {
            $depart = max($depart, $maintenantLocal->hour * 60 + $maintenantLocal->minute);
        }

        $creneaux = collect();

        foreach ($lignes as $ligne) {
            if ($ligne->employe_id !== null && $absents->contains($ligne->employe_id)) {
                continue;
            }

            $intervalles = [];

            foreach ($occupes[$ligne->id] ?? [] as [$debut, $fin]) {
                $intervalles[] = $journeeReguliere
                    ? [min(1440, max(0, intdiv($debut - $debutJour, 60))), min(1440, max(0, intdiv($fin - $debutJour, 60)))]
                    : [$this->enMinutesLocales($debut, $minuitLocal, $fuseau), $this->enMinutesLocales($fin, $minuitLocal, $fuseau)];
            }

            if ($bornes['pause'] !== null) {
                $intervalles[] = $bornes['pause'];
            }

            usort($intervalles, fn (array $a, array $b): int => $a[0] <=> $b[0]);

            $curseur = $depart;
            $trous = [];

            foreach ($intervalles as [$debut, $fin]) {
                if ($debut > $curseur + self::TROU_MINIMAL_MINUTES) {
                    $trous[] = [$curseur, min($debut, $bornes['fermeture'])];
                }

                $curseur = max($curseur, $fin);
            }

            if ($curseur < $bornes['fermeture'] - self::TROU_MINIMAL_MINUTES) {
                $trous[] = [$curseur, $bornes['fermeture']];
            }

            foreach ($trous as [$debut, $fin]) {
                if ($fin - $debut >= max(self::TROU_MINIMAL_MINUTES, $dureeMinutes)) {
                    $creneaux->push([
                        'ligne_planning_id' => $ligne->id,
                        'debut' => $minuitLocal->setTime(intdiv($debut, 60), $debut % 60)->utc(),
                        'fin' => $minuitLocal->setTime(intdiv($fin, 60), $fin % 60)->utc(),
                    ]);
                }
            }
        }

        return $creneaux->sortBy('debut')->values();
    }

    /**
     * Horodatage → minutes d'horloge locale du jour, bornées à
     * [0, 1440] pour un intervalle qui déborde du jour.
     */
    private function enMinutesLocales(int $horodatage, CarbonImmutable $minuitLocal, string $fuseau): int
    {
        $local = CarbonImmutable::createFromTimestamp($horodatage, $fuseau);

        if ($local->toDateString() < $minuitLocal->toDateString()) {
            return 0;
        }

        if ($local->toDateString() > $minuitLocal->toDateString()) {
            return 1440;
        }

        return $local->hour * 60 + $local->minute;
    }

    /** « 08:00 » ou « 08:00:00 » → minutes depuis minuit. */
    private function enMinutes(string $heure): int
    {
        [$heures, $minutes] = explode(':', $heure);

        return (int) $heures * 60 + (int) $minutes;
    }
}
