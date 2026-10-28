<?php

namespace App\Models\Concerns;

use App\Events\FilesPlanningModifiees;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;

/**
 * Urgences, demandes en ligne, liste d'attente (28.10.2026) : tout
 * changement prévient la clinique en temps réel, une fois la transaction
 * validée.
 */
trait ChangeLesFilesDuPlanning
{
    protected static function bootChangeLesFilesDuPlanning(): void
    {
        $prevenir = function (Model $modele): void {
            $cliniqueId = $modele->getAttribute('clinique_id');
            if ($cliniqueId !== null) {
                TempsReel::diffuser(fn () => FilesPlanningModifiees::dispatch((int) $cliniqueId));
            }
        };
        static::saved($prevenir);
        static::deleted($prevenir);
    }
}
