<?php

namespace App\Services;

use App\Models\Clinique;
use App\Models\HoraireOuverture;
use Carbon\CarbonImmutable;

/**
 * Une semaine d'une clinique (du lundi au dimanche), chaque jour avec
 * toutes ses heures d'ouverture au pas d'un rendez-vous, plage par plage,
 * libres ou non (coupure de midi exclue, heure passée jamais libre). Une heure est libre si une
 * ligne du planning l'est pour toute la durée demandée. Les jours hors de
 * la période (avant aujourd'hui, après la limite) gardent leur place,
 * `visible` à faux et sans heures. Calendrier des clients (un mois) et de
 * la clinique (sans limite pratique : proposer, placer loin).
 */
class SemaineDisponible
{
    /** Le pas des heures proposées, en minutes */
    public const int PAS_MINUTES = 20;

    public function __construct(private CalculateurCreneaux $calculateur) {}

    /**
     * L'heure est-elle l'une de celles que montre le calendrier (24.10.2026) :
     * sur la grille de sa plage (comptée depuis le début de la plage), hors
     * coupure, entre aujourd'hui et la limite ? Une heure à côté de la grille
     * prendrait deux créneaux à la fois.
     */
    public function estUneHeureProposee(Clinique $fiche, CarbonImmutable $debut, CarbonImmutable $limite): bool
    {
        $fuseau = $fiche->fuseau_horaire ?? 'Europe/Zurich';
        $local = $debut->setTimezone($fuseau);
        $jour = $local->startOfDay();
        if ($local->second !== 0 || $jour->lessThan(CarbonImmutable::today($fuseau)) || $jour->greaterThan($limite)) {
            return false;
        }

        $horaires = HoraireOuverture::withoutGlobalScope('clinique')->where('clinique_id', $fiche->id)->first()
            ?? new HoraireOuverture(HoraireOuverture::DEFAUTS);
        $minute = $local->hour * 60 + $local->minute;

        return in_array($minute, self::heuresDesPlages($this->calculateur->bornesDuJour($horaires, $jour)['plages']), true);
    }

    /**
     * Les heures proposées d'un jour, en minutes locales : chaque plage a
     * sa grille, au pas d'un rendez-vous depuis son début (l'après-midi qui
     * reprend à 13:30 propose 13:30), et un rendez-vous y tient entier.
     * Rien dans la coupure de midi.
     *
     * @param  list<array{int, int}>  $plages
     * @return list<int>
     */
    public static function heuresDesPlages(array $plages): array
    {
        $heures = [];
        foreach ($plages as [$debut, $fin]) {
            for ($minute = $debut; $minute + self::PAS_MINUTES <= $fin; $minute += self::PAS_MINUTES) {
                $heures[] = $minute;
            }
        }

        return $heures;
    }

    /**
     * @return array{semaine: string, precedente: ?string, suivante: ?string, duree_minutes: int, jours: list<array<string, mixed>>}
     */
    public function pour(Clinique $fiche, ?string $semaine, CarbonImmutable $limite, int $dureeMinutes = self::PAS_MINUTES): array
    {
        $fuseau = $fiche->fuseau_horaire ?? 'Europe/Zurich';
        $aujourdhui = CarbonImmutable::today($fuseau);
        $cetteSemaine = $aujourdhui->startOfWeek();
        $derniere = $limite->startOfWeek();
        $lundi = $semaine ? CarbonImmutable::parse($semaine, $fuseau)->startOfWeek() : $cetteSemaine;
        $lundi = $lundi->max($cetteSemaine)->min($derniere);

        $trous = $this->calculateur->creneauxDesCliniques($fiche->newCollection([$fiche]), 7, $dureeMinutes, $lundi)[$fiche->id];
        $horaires = HoraireOuverture::withoutGlobalScope('clinique')->where('clinique_id', $fiche->id)->first()
            ?? new HoraireOuverture(HoraireOuverture::DEFAUTS);
        $maintenant = now()->getTimestamp();
        $duree = $dureeMinutes * 60;

        $jours = [];
        foreach (range(0, 6) as $i) {
            $jour = $lundi->addDays($i);
            if ($jour->lessThan($aujourdhui) || $jour->greaterThan($limite)) {
                $jours[] = ['date' => $jour->toDateString(), 'visible' => false, 'ferme' => true, 'heures' => []];

                continue;
            }
            $bornes = $this->calculateur->bornesDuJour($horaires, $jour);
            // Les trous du jour en horodatages : une heure est libre si un trou la contient entière
            $libres = $trous[$i]->map(fn (array $trou): array => [$trou['debut']->getTimestamp(), $trou['fin']->getTimestamp()])->all();
            $heures = [];
            foreach (self::heuresDesPlages($bornes['plages']) as $minute) {
                $debut = $jour->setTime(intdiv($minute, 60), $minute % 60);
                $instant = $debut->getTimestamp();
                $heures[] = [
                    'debut' => $debut->toIso8601String(),
                    'libre' => $instant > $maintenant && collect($libres)->contains(fn (array $trou): bool => $trou[0] <= $instant && $trou[1] >= $instant + $duree),
                ];
            }
            $jours[] = ['date' => $jour->toDateString(), 'visible' => true, 'ferme' => $bornes['fermee'], 'heures' => $heures];
        }

        return [
            'semaine' => $lundi->toDateString(),
            'precedente' => $lundi->greaterThan($cetteSemaine) ? $lundi->subWeek()->toDateString() : null,
            'suivante' => $lundi->lessThan($derniere) ? $lundi->addWeek()->toDateString() : null,
            'duree_minutes' => $dureeMinutes,
            'jours' => $jours,
        ];
    }
}
