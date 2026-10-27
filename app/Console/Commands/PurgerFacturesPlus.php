<?php

namespace App\Console\Commands;

use App\Models\FactureProprietaire;
use Illuminate\Console\Command;

/**
 * Factures Plus d'un compte effacé, gardées comme pièces comptables
 * (CO 958f) : effacées dix ans après la fin de leur année d'émission
 * (28.10.2026). Celles d'un compte encore là restent. Planifiée chaque mois.
 */
class PurgerFacturesPlus extends Command
{
    protected $signature = 'factures:purger-plus';

    protected $description = 'Efface les factures Plus des comptes effacés, dix ans après leur année d’émission';

    public function handle(): int
    {
        // Émise en 2026 : gardée jusqu'au 31.12.2036, effacée dès 2037
        $limite = now('Europe/Zurich')->subYears(10)->startOfYear()->toDateString();

        $effacees = FactureProprietaire::query()->whereNull('user_id')->whereDate('emise_le', '<', $limite)->delete();

        $this->info($effacees.' facture(s) effacée(s).');

        return self::SUCCESS;
    }
}
