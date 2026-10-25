<?php

namespace App\Console\Commands;

use App\Mail\JetonCloudflareRefuse;
use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use App\Services\Domaines\JetonFournisseurRefuse;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
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
            if ($e instanceof JetonFournisseurRefuse) {
                $this->alerterJetonRefuse($e->statut);
            }
        }
    }

    /**
     * Jeton Cloudflare refusé : un e-mail à la plateforme, une fois par jour
     * tant que le refus dure (26.10.2026).
     */
    private function alerterJetonRefuse(int $statut): void
    {
        $adresse = config('plateforme.alerte_email');
        $jour = CarbonImmutable::now('Europe/Zurich')->toDateString();
        if (! $adresse || ! Cache::add('alerte:jeton-cloudflare:'.$jour, true, now()->addDays(2))) {
            return;
        }
        Mail::to($adresse)->send(new JetonCloudflareRefuse($statut));
    }
}
