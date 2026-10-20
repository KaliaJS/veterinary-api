<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\StatutRendezVous;
use App\Enums\TypeAbsence;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

        // Minuit local de chaque jour, et du lendemain du dernier pour
        // borner la période. Reparsés par date calendaire : un addDay
        // d'horloge dériverait au changement d'heure.
        $minuits = [];

        for ($i = 0; $i <= $nombreJours; $i++) {
            $minuits[] = CarbonImmutable::parse($premierJour->addDays($i)->toDateString(), $fuseau);
        }

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

        $jours = [];

        for ($i = 0; $i < $nombreJours; $i++) {
            $jours[] = $this->trousDuJour($horaires, $minuits[$i], $minuits[$i + 1], $fuseau, $dureeMinutes, $lignes, $absences, $rendezVous);
        }

        return $jours;
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
     * Le calcul d'un jour, sur les lignes, absences et rendez-vous déjà
     * lus pour la période.
     *
     * @param  Collection<int, LignePlanning>  $lignes
     * @param  Collection<int, Absence>  $absences
     * @param  Collection<int, RendezVous>  $rendezVousPeriode
     * @return Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>
     */
    private function trousDuJour(HoraireOuverture $horaires, CarbonImmutable $minuitLocal, CarbonImmutable $minuitSuivant, string $fuseau, int $dureeMinutes, Collection $lignes, Collection $absences, Collection $rendezVousPeriode): Collection
    {
        $bornes = $this->bornesDuJour($horaires, $minuitLocal);

        if ($bornes['fermee']) {
            return collect();
        }

        $date = $minuitLocal->toDateString();
        $absents = $absences
            ->filter(fn (Absence $absence): bool => $absence->debut_le->toDateString() <= $date && $absence->fin_le->toDateString() >= $date)
            ->pluck('employe_id');

        $debutJourUtc = $minuitLocal->utc();
        $finJourUtc = $minuitSuivant->utc();

        $occupes = $rendezVousPeriode
            ->filter(fn (RendezVous $rendezVous): bool => $rendezVous->starts_at->lt($finJourUtc) && $rendezVous->ends_at->gt($debutJourUtc))
            ->groupBy('ligne_planning_id');

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

            foreach ($occupes->get($ligne->id, collect()) as $rendezVous) {
                $intervalles[] = [
                    $this->enMinutesLocales($rendezVous->starts_at, $minuitLocal, $fuseau),
                    $this->enMinutesLocales($rendezVous->ends_at, $minuitLocal, $fuseau),
                ];
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
     * Instant UTC → minutes d'horloge locale du jour, bornées à
     * [0, 1440] pour un intervalle qui déborde du jour.
     */
    private function enMinutesLocales(CarbonInterface $instant, CarbonImmutable $minuitLocal, string $fuseau): int
    {
        $local = $instant->avoidMutation()->setTimezone($fuseau);

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
