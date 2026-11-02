<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Le remplissage d'une suite de jours, pour la bande des jours de l'écran
 * (Bloc opératoire, Planning ; demande de Jim, 02.11.2026) : pour chaque
 * jour, trois moments à l'heure de la clinique (le matin avant midi, midi
 * de 12 h à 14 h, l'après-midi ensuite), vrais quand une plage occupée y
 * déborde, même d'une minute.
 */
class DayOccupancy
{
    /** Bornes des moments, en minutes depuis minuit */
    private const array MOMENTS = [[0, 720], [720, 840], [840, 1440]];

    /** Le plus long écart accepté entre le premier et le dernier jour */
    public const int MAX_DAYS = 92;

    /**
     * @param  iterable<array{0: CarbonInterface, 1: CarbonInterface}>  $plages  début et fin de chaque plage occupée
     * @return array<string, array{0: bool, 1: bool, 2: bool}> 'AAAA-MM-JJ' → [matin, midi, après-midi]
     */
    public function for(string $fuseau, CarbonImmutable $du, CarbonImmutable $au, iterable $plages): array
    {
        $jours = [];
        for ($j = $du->startOfDay(); $j->lessThanOrEqualTo($au); $j = $j->addDay()) {
            $jours[$j->toDateString()] = [false, false, false];
        }

        foreach ($plages as [$debut, $fin]) {
            $debut = CarbonImmutable::instance($debut)->setTimezone($fuseau);
            $fin = CarbonImmutable::instance($fin)->setTimezone($fuseau);
            if ($fin->lessThanOrEqualTo($debut)) {
                $fin = $debut->addMinute();
            }
            for ($jour = $debut->startOfDay(); $jour->lessThan($fin); $jour = $jour->addDay()) {
                $cle = $jour->toDateString();
                if (! isset($jours[$cle])) {
                    continue;
                }
                $de = max(0, (int) $jour->diffInMinutes($debut, false));
                $a = min(1440, (int) $jour->diffInMinutes($fin, false));
                foreach (self::MOMENTS as $i => [$ouvre, $ferme]) {
                    if ($de < $ferme && $a > $ouvre) {
                        $jours[$cle][$i] = true;
                    }
                }
            }
        }

        return $jours;
    }

    /**
     * La période demandée (`du`, `au`), validée, à l'heure de la clinique.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(Request $request, string $fuseau): array
    {
        $donnees = $request->validate([
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]);
        $du = CarbonImmutable::parse($donnees['du'], $fuseau)->startOfDay();
        $au = CarbonImmutable::parse($donnees['au'], $fuseau)->startOfDay();
        if ($du->diffInDays($au) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['au' => 'Pas plus de '.self::MAX_DAYS.' jours à la fois.']);
        }

        return [$du, $au];
    }
}
