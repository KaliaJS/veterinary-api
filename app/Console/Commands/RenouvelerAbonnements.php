<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use App\Services\Abonnements\AbonnementSaas;
use App\Support\MasquageSql;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renouvelle les abonnements arrivés à échéance (organisations et Plus des
 * propriétaires), retente les prélèvements refusés (une fois par jour,
 * 3 jours durant, puis l'accès se ferme) et prévient quand la carte expire
 * avant le prochain prélèvement. Planifiée toutes les heures : chaque abonné
 * n'est traité qu'une fois, son échéance avançant au paiement.
 */
class RenouvelerAbonnements extends Command
{
    protected $signature = 'abonnements:renouveler';

    protected $description = 'Renouvelle les abonnements échus et retente les paiements refusés';

    public function handle(AbonnementSaas $abonnements): int
    {
        $aujourdhui = now('Europe/Zurich')->toDateString();
        $traites = 0;

        foreach ([Organisation::query(), User::query()] as $requete) {
            $echus = (clone $requete)->where('statut_abonnement', 'actif')->whereDate('periode_fin', '<=', $aujourdhui);
            $aRetenter = (clone $requete)->where('statut_abonnement', 'impaye')->where('prochaine_tentative_le', '<=', now());

            $this->parcourir($echus, fn ($abonne) => $abonnements->renouveler($abonne), $traites);
            $this->parcourir($aRetenter, fn ($abonne) => $abonnements->retenter($abonne), $traites);
        }

        $alertes = $abonnements->alerterCartes();

        $this->info($traites.' abonnement(s) traité(s), '.$alertes.' alerte(s) de carte.');

        return self::SUCCESS;
    }

    /**
     * Un abonné en échec n'empêche pas les autres d'être traités.
     *
     * @param  Builder<Organisation>|Builder<User>  $requete
     * @param  callable(Organisation|User): mixed  $traiter
     */
    private function parcourir(Builder $requete, callable $traiter, int &$traites): void
    {
        $requete->each(function ($abonne) use ($traiter, &$traites): void {
            try {
                $traiter($abonne);
                $traites++;
            } catch (Throwable $e) {
                // Sans les valeurs d'un message SQL (24.10.2026)
                Log::error('Renouvellement impossible.', ['abonne' => $abonne::class.':'.$abonne->id, 'erreur' => MasquageSql::message($e->getMessage())]);
            }
        });
    }
}
