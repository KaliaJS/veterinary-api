<?php

namespace App\Console\Commands;

use App\Services\Catalogue\ImportSwissmedic;
use Illuminate\Console\Command;

/** Catalogue des médicaments vétérinaires : import du flux OGD Swissmedic (mensuel en production). */
class ImporterCatalogueSwissmedic extends Command
{
    protected $signature = 'catalogue:swissmedic {--fichier= : archive ZIP locale au lieu du téléchargement} {--force : importer même si le fichier est identique}';

    protected $description = 'Importe les médicaments vétérinaires autorisés depuis le flux OGD de Swissmedic';

    public function handle(ImportSwissmedic $import): int
    {
        $journal = $import->importer($this->option('fichier') ?: null, (bool) $this->option('force'));
        $this->info(sprintf('Swissmedic : %s · %d ajoutés, %d modifiés, %d retirés · export du %s · %d ms',
            $journal->statut, $journal->ajoutes, $journal->modifies, $journal->retires, $journal->export_le?->toDateString() ?? '?', $journal->duree_ms));
        if ($journal->message) {
            $this->line($journal->message);
        }

        return self::SUCCESS;
    }
}
