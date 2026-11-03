<?php

namespace App\Support;

/**
 * La durée d'une ligne d'ordonnance, en jours (dossier patient, 03.11.2026).
 * L'ordonnance garde la durée telle qu'écrite (« 21 jours », « 3 semaines »,
 * « Prise unique », « Selon besoin ») ; le dossier compte les jours faits
 * sur les jours prévus. Une durée qui ne se lit pas (« Selon besoin »,
 * « jusqu'au contrôle ») ne donne pas de nombre : rien n'est deviné.
 */
final class TreatmentDuration
{
    /** Une ordonnance sans durée lisible reste valable trente jours (décision du 03.11.2026) */
    public const DEFAULT_VALIDITY_DAYS = 30;

    /** Jours par unité écrite */
    private const UNITS = [
        'j' => 1, 'jour' => 1, 'jours' => 1,
        'sem' => 7, 'semaine' => 7, 'semaines' => 7,
        'mois' => 30,
    ];

    public static function days(?string $duration): ?int
    {
        $text = mb_strtolower(trim((string) $duration));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, 'prise unique') || str_contains($text, 'dose unique')) {
            return 1;
        }
        // « 10 jours », « pendant 10 jours » ; une fourchette « 7 à 10 jours » compte pour 10
        $text = (string) preg_replace('/^pendant\s+/u', '', $text);
        if (preg_match('/^(?:\d{1,3}\s*(?:à|-|–)\s*)?(\d{1,3})\s*(j|jours?|sem|semaines?|mois)\b\.?$/u', $text, $m) === 1) {
            return (int) $m[1] * self::UNITS[$m[2]];
        }

        return null;
    }
}
