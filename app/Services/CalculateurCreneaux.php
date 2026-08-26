<?php

namespace App\Services;

use App\Enums\StatutAbsence;
use App\Enums\StatutRendezVous;
use App\Enums\TypeAbsence;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Créneaux libres et bornes de journée (zones « FERMÉ ») calculés
 * depuis les horaires de la clinique — jamais des constantes. Tout est
 * exprimé en minutes depuis minuit du jour demandé, datetimes UTC comme
 * le stockage ; la conversion au fuseau d'affichage reste côté front.
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

    /**
     * Bornes effectives d'un jour donné — les zones fermées de la grille
     * en découlent : avant l'ouverture, après la fermeture, la pause.
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
     * Trous libres du jour, par ligne, triés par début. Chaque créneau
     * est l'intervalle complet du trou (le front y découpe la durée).
     *
     * @return Collection<int, array{ligne_planning_id: int, debut: CarbonImmutable, fin: CarbonImmutable}>
     */
    public function creneauxLibres(CarbonImmutable $jour, int $dureeMinutes = self::TROU_MINIMAL_MINUTES, ?int $lignePlanningId = null): Collection
    {
        $bornes = $this->bornesDuJour($this->horaires(), $jour);

        if ($bornes['fermee']) {
            return collect();
        }

        $lignes = LignePlanning::query()
            ->when($lignePlanningId !== null, fn ($requete) => $requete->whereKey($lignePlanningId))
            ->whereNotIn('id', $this->lignesBloquees($jour))
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        $debutJour = $jour->startOfDay();

        $occupes = RendezVous::query()
            ->whereIn('ligne_planning_id', $lignes->pluck('id'))
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where('type', '!=', TypeRendezVous::Tampon)
            ->where('starts_at', '<', $debutJour->addDay())
            ->where('ends_at', '>', $debutJour)
            ->get()
            ->groupBy('ligne_planning_id');

        $depart = $bornes['ouverture'];

        if ($jour->isSameDay(now())) {
            $depart = max($depart, now()->hour * 60 + now()->minute);
        }

        $creneaux = collect();

        foreach ($lignes as $ligne) {
            $intervalles = [];

            foreach ($occupes->get($ligne->id, collect()) as $rendezVous) {
                $intervalles[] = [
                    max(0, intdiv($rendezVous->starts_at->getTimestamp() - $debutJour->getTimestamp(), 60)),
                    min(1440, intdiv($rendezVous->ends_at->getTimestamp() - $debutJour->getTimestamp(), 60)),
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
                        'debut' => $debutJour->addMinutes($debut),
                        'fin' => $debutJour->addMinutes($fin),
                    ]);
                }
            }
        }

        return $creneaux->sortBy('debut')->values();
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

    /** Une absence validée hors garde bloque la colonne du praticien. */
    public function ligneEstBloquee(LignePlanning $ligne, CarbonImmutable $jour): bool
    {
        return $ligne->employe_id !== null && Absence::query()
            ->where('employe_id', $ligne->employe_id)
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $jour)
            ->whereDate('fin_le', '>=', $jour)
            ->exists();
    }

    /** @return list<int> */
    private function lignesBloquees(CarbonImmutable $jour): array
    {
        return LignePlanning::query()
            ->whereNotNull('employe_id')
            ->whereHas('employe.absences', fn ($requete) => $requete
                ->where('statut', StatutAbsence::Validee)
                ->where('type', '!=', TypeAbsence::Garde)
                ->whereDate('debut_le', '<=', $jour)
                ->whereDate('fin_le', '>=', $jour))
            ->pluck('id')
            ->all();
    }

    /** « 08:00 » ou « 08:00:00 » → minutes depuis minuit. */
    private function enMinutes(string $heure): int
    {
        [$heures, $minutes] = explode(':', $heure);

        return (int) $heures * 60 + (int) $minutes;
    }
}
