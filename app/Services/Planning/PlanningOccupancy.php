<?php

namespace App\Services\Planning;

use App\Enums\RoleLignePlanning;
use App\Enums\StatutAbsence;
use App\Enums\StatutRendezVous;
use App\Enums\TypeAbsence;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Support\WallClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * L'occupation du planning sur une suite de jours (statistiques de la
 * Caisse, 06.11.2026) : les minutes réservées sur les minutes ouvertes,
 * par colonne de praticien (consultation), sans rien inventer.
 *
 * Les minutes ouvertes sont les plages des horaires de la clinique, jour
 * par jour, en heure murale de la clinique ; un praticien sous absence
 * bloquante (validée, hors garde) n'a pas de minutes ouvertes ce jour-là.
 * Les minutes réservées sont celles des rendez-vous (ni annulés, ni
 * tampons) qui tombent dans ces plages : la coupure de midi et le soir ne
 * comptent pas. Mêmes règles que CalculateurCreneaux.
 *
 * Les colonnes et les horaires se lisent une fois (load), puis chaque
 * période coûte deux requêtes (absences, rendez-vous), quel que soit le
 * nombre de jours, de colonnes ou de rendez-vous ; les rendez-vous se
 * lisent en flux, colonne après colonne (vérification du 06.11.2026).
 */
class PlanningOccupancy
{
    /** @var Collection<int, LignePlanning> */
    private Collection $lanes;

    /** @var array<int, HoraireOuverture> */
    private array $hours = [];

    /** @var array<int, string> */
    private array $timezones = [];

    /** @param  EloquentCollection<int, Clinique>  $clinics */
    public function load(EloquentCollection $clinics): self
    {
        $ids = $clinics->modelKeys();
        $this->timezones = $clinics->mapWithKeys(fn (Clinique $clinique): array => [$clinique->id => $clinique->fuseau_horaire ?: 'Europe/Zurich'])->all();

        $stored = HoraireOuverture::withoutGlobalScope('clinique')->whereIn('clinique_id', $ids)->get()->keyBy('clinique_id');
        foreach ($ids as $id) {
            $this->hours[$id] = $stored->get($id) ?? new HoraireOuverture(HoraireOuverture::DEFAUTS);
        }

        // Les colonnes des praticiens en service ; une colonne d'un employé retiré de la clinique n'offre plus de créneaux
        $this->lanes = LignePlanning::withoutGlobalScope('clinique')
            ->enService()
            ->whereIn('clinique_id', $ids)
            ->where('role', RoleLignePlanning::Consultation)
            ->whereNotNull('employe_id')
            ->orderBy('clinique_id')
            ->orderBy('ordre')
            ->orderBy('id')
            ->get(['id', 'clinique_id', 'employe_id', 'nom', 'initiales']);

        return $this;
    }

    /** @return Collection<int, LignePlanning> */
    public function lanes(): Collection
    {
        return $this->lanes;
    }

    /**
     * @param  string  $from  premier jour, AAAA-MM-JJ
     * @param  string  $to  dernier jour, compris
     * @return array{booked: int, open: int, lanes: array<int, array{booked: int, open: int}>, heat: array<string, array{booked: int, open: int}>}
     */
    public function measure(string $from, string $to): array
    {
        $result = ['booked' => 0, 'open' => 0, 'lanes' => [], 'heat' => []];
        if ($this->lanes->isEmpty()) {
            return $result;
        }

        $days = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $days[] = [$day->toDateString(), $day->dayOfWeekIso, $day];
        }

        $absent = $this->absences($from, $to);
        // Les rendez-vous lus en flux, colonne après colonne : seuls ceux de la colonne en cours sont en mémoire
        $rows = $this->bookingRows($from, $to);
        $windows = [];

        foreach ($this->lanes->sortBy('id') as $lane) {
            $booked = [];
            while ($rows->valid() && (int) $rows->current()->ligne_planning_id <= $lane->id) {
                if ((int) $rows->current()->ligne_planning_id === $lane->id) {
                    $this->bucket($booked, $rows->current(), $this->timezones[$lane->clinique_id], $from, $to);
                }
                $rows->next();
            }

            $result['lanes'][$lane->id] = ['booked' => 0, 'open' => 0];
            /* Les heures ouvertes se comptent par jour de la semaine (combien de
               jours ouverts) ; seules les journées qui ont des rendez-vous se
               lisent heure par heure : dix ans restent rapides. */
            $openDays = [];
            foreach ($days as [$date, $weekday, $day]) {
                $window = $windows[$lane->clinique_id][$weekday] ??= $this->windowOf($this->hours[$lane->clinique_id]->rangesOn($day));
                if ($window['total'] === 0 || isset($absent[$lane->employe_id][$date])) {
                    continue;
                }
                $openDays[$weekday] = ($openDays[$weekday] ?? 0) + 1;
                if (! isset($booked[$date])) {
                    continue;
                }
                foreach ($this->merge($booked[$date]) as [$busyStart, $busyEnd]) {
                    foreach ($window['ranges'] as [$start, $end]) {
                        $low = max($busyStart, $start);
                        $high = min($busyEnd, $end);
                        // Heure par heure, pour la semaine type
                        for ($hour = intdiv($low, 60); $hour * 60 < $high; $hour++) {
                            $taken = min($high, $hour * 60 + 60) - max($low, $hour * 60);
                            $cell = $weekday.'-'.$hour;
                            $result['heat'][$cell] ??= ['booked' => 0, 'open' => 0];
                            $result['heat'][$cell]['booked'] += $taken;
                            $result['lanes'][$lane->id]['booked'] += $taken;
                        }
                    }
                }
            }
            foreach ($openDays as $weekday => $count) {
                $window = $windows[$lane->clinique_id][$weekday];
                $result['lanes'][$lane->id]['open'] += $count * $window['total'];
                foreach ($window['hours'] as $hour => $minutes) {
                    $cell = $weekday.'-'.$hour;
                    $result['heat'][$cell] ??= ['booked' => 0, 'open' => 0];
                    $result['heat'][$cell]['open'] += $count * $minutes;
                }
            }

            $result['booked'] += $result['lanes'][$lane->id]['booked'];
            $result['open'] += $result['lanes'][$lane->id]['open'];
        }

        return $result;
    }

    /**
     * Les jours d'absence bloquante de chaque employé des colonnes.
     *
     * @return array<int, array<string, true>>
     */
    private function absences(string $from, string $to): array
    {
        $absences = Absence::withoutGlobalScope('clinique')
            ->whereIn('employe_id', $this->lanes->pluck('employe_id')->unique()->values())
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $to)
            ->whereDate('fin_le', '>=', $from)
            ->toBase()
            ->get(['employe_id', 'debut_le', 'fin_le']);

        $days = [];
        foreach ($absences as $absence) {
            $first = max(substr((string) $absence->debut_le, 0, 10), $from);
            $last = min(substr((string) $absence->fin_le, 0, 10), $to);
            for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
                $days[(int) $absence->employe_id][$day->toDateString()] = true;
            }
        }

        return $days;
    }

    /**
     * Les rendez-vous qui occupent (ni annulés, ni tampons), triés par
     * colonne, en une requête lue ligne à ligne.
     *
     * @return \Generator<int, \stdClass>
     */
    private function bookingRows(string $from, string $to): \Generator
    {
        // Une marge d'un jour de chaque côté : les fuseaux décalent les bornes
        yield from RendezVous::withoutGlobalScope('clinique')
            ->whereIn('ligne_planning_id', $this->lanes->pluck('id')->all())
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where('type', '!=', TypeRendezVous::Tampon)
            ->where('starts_at', '<', CarbonImmutable::parse($to, 'UTC')->addDays(2))
            ->where('ends_at', '>', CarbonImmutable::parse($from, 'UTC')->subDay())
            ->orderBy('ligne_planning_id')
            ->toBase()
            ->select(['ligne_planning_id', 'starts_at', 'ends_at'])
            ->cursor();
    }

    /**
     * Les plages ouvertes d'un jour, leur total et leurs minutes heure par heure.
     *
     * @param  list<array{int, int}>  $ranges
     * @return array{ranges: list<array{int, int}>, total: int, hours: array<int, int>}
     */
    private function windowOf(array $ranges): array
    {
        $hours = [];
        $total = 0;
        foreach ($ranges as [$start, $end]) {
            $total += $end - $start;
            for ($hour = intdiv($start, 60); $hour * 60 < $end; $hour++) {
                $minutes = min($end, $hour * 60 + 60) - max($start, $hour * 60);
                if ($minutes > 0) {
                    $hours[$hour] = ($hours[$hour] ?? 0) + $minutes;
                }
            }
        }

        return ['ranges' => $ranges, 'total' => $total, 'hours' => $hours];
    }

    /** @var array<string, WallClock> L'heure murale de chaque fuseau, lue sans objet date par rendez-vous */
    private array $clocks = [];

    private function wallClock(string $utc, string $timezone): int
    {
        return ($this->clocks[$timezone] ??= new WallClock($timezone))->local($utc);
    }

    /**
     * Un rendez-vous rangé jour par jour, en minutes murales de la clinique depuis minuit.
     *
     * @param  array<string, list<array{int, int}>>  $booked
     */
    private function bucket(array &$booked, object $row, string $timezone, string $from, string $to): void
    {
        $start = $this->wallClock((string) $row->starts_at, $timezone);
        $end = $this->wallClock((string) $row->ends_at, $timezone);
        if ($end <= $start) {
            return;
        }
        for ($midnight = $start - $start % 86400; $midnight < $end; $midnight += 86400) {
            $date = gmdate('Y-m-d', $midnight);
            if ($date < $from || $date > $to) {
                continue;
            }
            $low = intdiv(max($start, $midnight) - $midnight, 60);
            $high = intdiv(min($end, $midnight + 86400) - $midnight, 60);
            if ($high > $low) {
                $booked[$date][] = [$low, $high];
            }
        }
    }

    /**
     * Des plages qui se chevauchent (les rendez-vous le peuvent) ne comptent qu'une fois.
     *
     * @param  list<array{int, int}>  $intervals
     * @return list<array{int, int}>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }
}
