<?php

namespace App\Console\Commands;

use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use Illuminate\Console\Command;
use Throwable;

/** Revérifie les domaines en attente (DNS en cours de propagation). Planifiée toutes les 10 minutes. */
class VerifierDomainesSites extends Command
{
    protected $signature = 'sites:verifier-domaines';

    protected $description = 'Revérifie les domaines personnalisés des sites encore en attente';

    public function handle(DomaineSite $domaines): int
    {
        // En attente : vérifiés, ou retirés après 14 jours (le nom se libère)
        SiteWeb::withoutGlobalScopes()
            ->whereNotNull('domaine')
            ->where('domaine_statut', '!=', DomaineSite::ACTIF)
            ->each(fn (SiteWeb $site) => $this->sansArreter($site, fn () => $site->domaine_relie_le !== null && $site->domaine_relie_le->lt(now()->subDays(DomaineSite::ATTENTE_JOURS))
                ? $domaines->retirer($site)
                : $domaines->verifier($site)));

        // Actifs : revérifiés chaque heure (DNS changé, domaine vendu)
        SiteWeb::withoutGlobalScopes()
            ->where('domaine_statut', DomaineSite::ACTIF)
            ->where(fn ($requete) => $requete->whereNull('domaine_verifie_le')->orWhere('domaine_verifie_le', '<', now()->subHour()))
            ->each(fn (SiteWeb $site) => $this->sansArreter($site, fn () => $domaines->verifier($site)));

        return self::SUCCESS;
    }

    /** Une erreur du fournisseur sur un domaine n'arrête pas les autres : elle va au journal. */
    private function sansArreter(SiteWeb $site, callable $geste): void
    {
        try {
            $geste();
        } catch (Throwable $e) {
            report($e);
            $this->warn("Domaine {$site->domaine} : vérification impossible, réessayée au passage suivant.");
        }
    }
}
