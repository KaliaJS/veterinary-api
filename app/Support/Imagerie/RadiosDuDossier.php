<?php

namespace App\Support\Imagerie;

use App\Models\ClicheDicom;
use App\Models\ExamenImagerie;
use App\Models\Patient;

/**
 * Les radios du dossier d'un animal (28.10.2026) : l'examen et ses clichés
 * se résolvent toujours par le patient (scopé à l'organisation), scope
 * clinique levé : le dossier est partagé entre cliniques sœurs, jamais
 * avec une autre organisation. Un cliché d'un autre examen : 404.
 */
final class RadiosDuDossier
{
    public static function examen(Patient $patient, int $examen): ExamenImagerie
    {
        return $patient->examensImagerie()->withoutGlobalScope('clinique')->findOrFail($examen);
    }

    public static function cliche(Patient $patient, int $examen, int $cliche): ClicheDicom
    {
        return self::examen($patient, $examen)->cliches()->findOrFail($cliche);
    }

    /** Pour la validation : le cliché visé par la route, ou null (le contrôleur répondra 404) */
    public static function depuisLaRoute(mixed $patient, mixed $examen, mixed $cliche): ?ClicheDicom
    {
        if (! $patient instanceof Patient || ! is_numeric($examen) || ! is_numeric($cliche)) {
            return null;
        }

        return $patient->examensImagerie()->withoutGlobalScope('clinique')->find((int) $examen)?->cliches()->find((int) $cliche);
    }
}
