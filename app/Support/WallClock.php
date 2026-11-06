<?php

namespace App\Support;

use DateTimeZone;

/**
 * L'heure murale d'un fuseau pour des horodatages UTC de la base, lue en
 * masse (statistiques de la Caisse, 06.11.2026) : les changements d'heure
 * du fuseau se lisent une fois, puis chaque horodatage trouve son décalage
 * par dichotomie, sans objet date par ligne. Le résultat est un nombre de
 * secondes « comme si c'était UTC » : le jour et la minute de la clinique
 * s'y lisent avec gmdate.
 */
class WallClock
{
    /** @var list<array{int, int}> début de chaque période et son décalage, dans l'ordre */
    private array $transitions = [];

    public function __construct(string $timezone)
    {
        // De 1970 à 2100 : bien au-delà des dix ans demandés
        foreach ((new DateTimeZone($timezone))->getTransitions(0, 4102444800) ?: [] as $transition) {
            $this->transitions[] = [(int) $transition['ts'], (int) $transition['offset']];
        }
        if ($this->transitions === []) {
            $this->transitions[] = [0, 0];
        }
    }

    /** Les secondes murales d'un horodatage UTC « AAAA-MM-JJ HH:MM:SS » */
    public function local(string $utc): int
    {
        $timestamp = (int) strtotime($utc.' UTC');
        [$low, $high] = [0, count($this->transitions) - 1];
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->transitions[$middle][0] <= $timestamp) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $timestamp + $this->transitions[$low][1];
    }

    /** Le jour du calendrier du fuseau, AAAA-MM-JJ */
    public function day(string $utc): string
    {
        return gmdate('Y-m-d', $this->local($utc));
    }
}
