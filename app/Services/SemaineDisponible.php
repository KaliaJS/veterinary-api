<?php

namespace App\Services;

use App\Models\Clinique;
use App\Models\HoraireOuverture;
use Carbon\CarbonImmutable;

/**
 * Une semaine d'une clinique (du lundi au dimanche), chaque jour avec
 * toutes ses heures d'ouverture au pas d'un rendez-vous, libres ou non
 * (pause exclue, heure passée jamais libre). Une heure est libre si une
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
     * sur le pas de la grille compté depuis l'ouverture, hors pause, entre
     * aujourd'hui et la limite ? Une heure à côté de la grille prendrait deux
     * créneaux à la fois.
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
        $bornes = $this->calculateur->bornesDuJour($horaires, $jour);
        $minute = $local->hour * 60 + $local->minute;

        return ! $bornes['fermee']
            && $minute >= $bornes['ouverture']
            && $minute + self::PAS_MINUTES <= $bornes['fermeture']
            && ($minute - $bornes['ouverture']) % self::PAS_MINUTES === 0
            && ! ($bornes['pause'] && $minute < $bornes['pause'][1] && $minute + self::PAS_MINUTES > $bornes['pause'][0]);
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
            for ($minute = $bornes['ouverture']; ! $bornes['fermee'] && $minute + self::PAS_MINUTES <= $bornes['fermeture']; $minute += self::PAS_MINUTES) {
                if ($bornes['pause'] && $minute < $bornes['pause'][1] && $minute + self::PAS_MINUTES > $bornes['pause'][0]) {
                    continue;
                }
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
