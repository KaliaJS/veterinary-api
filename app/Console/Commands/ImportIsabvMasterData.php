<?php

namespace App\Console\Commands;

use App\Services\Isabv\ImportMasterData;
use Illuminate\Console\Command;

/** SI ABV : les données de base de l'OSAV (quotidien en production, à la main ailleurs). */
class ImportIsabvMasterData extends Command
{
    protected $signature = 'isabv:master-data {--force : relire même si l’OSAV n’a rien changé}';

    protected $description = 'Importe les données de base SI ABV de l’OSAV (préparations, espèces, diagnostics, fréquences, voies)';

    public function handle(ImportMasterData $import): int
    {
        $journal = $import->run((bool) $this->option('force'));
        $this->info(sprintf('SI ABV : %s · %d ajoutés, %d modifiés, %d retirés · %d ms', $journal->statut, $journal->ajoutes, $journal->modifies, $journal->retires, $journal->duree_ms));
        if ($journal->message) {
            $this->line($journal->message);
        }

        return self::SUCCESS;
    }
}
