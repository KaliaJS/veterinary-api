<?php

namespace App\Console\Commands;

use App\Services\Facturation\PatientDebts;
use Illuminate\Console\Command;

/**
 * Recalcule la dette de chaque animal depuis ses factures dues (03.11.2026).
 * Lancée une fois par la migration ; utile après une reprise de données.
 */
class RecomputeDebts extends Command
{
    protected $signature = 'invoices:recompute-debts';

    protected $description = 'Recalcule la dette des animaux depuis leurs factures dues';

    public function handle(): int
    {
        $count = PatientDebts::refreshAll();
        $this->info("Dettes recalculées depuis les factures : {$count} animal(aux) avec une facture due.");

        return self::SUCCESS;
    }
}
