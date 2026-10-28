<?php

namespace App\Console\Commands;

use App\Auth\RevocationJetons;
use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Console\Command;

/**
 * Fin de contrat passée (23.10.2026) : l'accès se ferme déjà à chaque
 * requête (middleware `clinique`) ; la fiche passe aussi en accès suspendu
 * et ses jetons disparaissent, pour que rien ne reste ouvert ailleurs.
 * Planifiée chaque heure : la nuit de chaque clinique, à son fuseau.
 */
class FermerContratsTermines extends Command
{
    protected $signature = 'equipe:fermer-contrats';

    protected $description = 'Suspend l’accès des employés dont le contrat est terminé et révoque leurs jetons';

    public function handle(RevocationJetons $revocation): int
    {
        $fermes = 0;

        // Une veille large (fuseaux en avance sur Zurich), puis la date de
        // chaque clinique : fermé quand le contrat est fini dans toutes ses
        // cliniques, comme à la porte (28.10.2026)
        Employe::withoutGlobalScopes()
            ->with('user', 'organisation', 'cliniques')
            ->where('acces_suspendu', false)
            ->whereNotNull('fin_contrat_le')
            ->whereDate('fin_contrat_le', '<', aujourdhui()->addDay())
            ->each(function (Employe $employe) use ($revocation, &$fermes): void {
                $fini = $employe->cliniques->isEmpty()
                    ? $employe->fin_contrat_le->lt(aujourdhui())
                    : $employe->cliniques->every(fn (Clinique $clinique): bool => $employe->fin_contrat_le->lt(aujourdhui($clinique)));
                if (! $fini) {
                    return;
                }
                $employe->forceFill(['acces_suspendu' => true])->save();
                $revocation->pourEmploye($employe);
                $fermes++;
            });

        $this->info($fermes.' accès fermé(s).');

        return self::SUCCESS;
    }
}
