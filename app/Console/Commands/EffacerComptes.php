<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Comptes\SuppressionCompte;
use Illuminate\Console\Command;
use Throwable;

/**
 * Efface les comptes dont la suppression a été demandée il y a 30 jours
 * (27.10.2026). Un compte qui échoue (Stripe en panne) attend la nuit
 * suivante, sans bloquer les autres. Planifiée chaque nuit.
 */
class EffacerComptes extends Command
{
    protected $signature = 'comptes:effacer';

    protected $description = 'Efface les comptes dont la suppression a été demandée il y a 30 jours';

    public function handle(SuppressionCompte $suppression): int
    {
        $effaces = 0;

        User::query()
            ->whereNotNull('suppression_demandee_le')
            ->where('suppression_demandee_le', '<=', now()->subDays(SuppressionCompte::DELAI_JOURS))
            // Par identifiant : les effacements en cours de route ne font sauter aucune ligne
            ->lazyById(100)
            ->each(function (User $user) use ($suppression, &$effaces): void {
                try {
                    if ($suppression->effacer($user)) {
                        $effaces++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info($effaces.' compte(s) effacé(s).');

        return self::SUCCESS;
    }
}
