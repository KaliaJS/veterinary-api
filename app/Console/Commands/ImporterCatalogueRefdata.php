<?php

namespace App\Console\Commands;

use App\Services\Catalogue\ImportRefdata;
use Illuminate\Console\Command;

/** Catalogue : GTIN et noms français des emballages depuis la base d'articles Refdata (hebdomadaire en production). */
class ImporterCatalogueRefdata extends Command
{
    protected $signature = 'catalogue:refdata {--fichier= : archive ZIP locale au lieu du téléchargement} {--force : importer même si le fichier est identique}';

    protected $description = 'Importe les codes-barres (GTIN) des emballages vétérinaires depuis Refdata';

    public function handle(ImportRefdata $import): int
    {
        $journal = $import->importer($this->option('fichier') ?: null, (bool) $this->option('force'));
        $this->info(sprintf('Refdata : %s · %d emballages mis à jour · %d ms', $journal->statut, $journal->modifies, $journal->duree_ms));
        if ($journal->message) {
            $this->line($journal->message);
        }

        return self::SUCCESS;
    }
}
