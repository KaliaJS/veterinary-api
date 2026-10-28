<?php

namespace App\Models\Concerns;

use App\Events\FilesPlanningModifiees;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
                DB::afterCommit(fn () => FilesPlanningModifiees::dispatch((int) $cliniqueId));
            }
        };
        static::saved($prevenir);
        static::deleted($prevenir);
    }
}
