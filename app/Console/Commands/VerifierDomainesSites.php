<?php

namespace App\Console\Commands;

use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use Illuminate\Console\Command;

/** Revérifie les domaines en attente (DNS en cours de propagation). Planifiée toutes les 10 minutes. */
class VerifierDomainesSites extends Command
{
    protected $signature = 'sites:verifier-domaines';

    protected $description = 'Revérifie les domaines personnalisés des sites encore en attente';

    public function handle(DomaineSite $domaines): int
    {
        SiteWeb::withoutGlobalScopes()
            ->whereNotNull('domaine')
            ->where('domaine_statut', '!=', DomaineSite::ACTIF)
            ->each(fn (SiteWeb $site) => $domaines->verifier($site));

        return self::SUCCESS;
    }
}
