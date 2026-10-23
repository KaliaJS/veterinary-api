<?php

namespace App\Console\Commands;

use App\Auth\RevocationJetons;
use App\Models\Employe;
use Illuminate\Console\Command;

/**
 * Fin de contrat passée (23.10.2026) : l'accès se ferme déjà à chaque
 * requête (middleware `clinique`) ; chaque nuit, la fiche passe aussi
 * en accès suspendu et ses jetons disparaissent, pour que rien ne reste
 * ouvert ailleurs. Planifiée chaque nuit.
 */
class FermerContratsTermines extends Command
{
    protected $signature = 'equipe:fermer-contrats';

    protected $description = 'Suspend l’accès des employés dont le contrat est terminé et révoque leurs jetons';

    public function handle(RevocationJetons $revocation): int
    {
        $fermes = 0;

        Employe::withoutGlobalScopes()
            ->with('user', 'organisation')
            ->where('acces_suspendu', false)
            ->whereNotNull('fin_contrat_le')
            ->whereDate('fin_contrat_le', '<', today())
            ->each(function (Employe $employe) use ($revocation, &$fermes): void {
                $employe->forceFill(['acces_suspendu' => true])->save();
                $revocation->pourEmploye($employe);
                $fermes++;
            });

        $this->info($fermes.' accès fermé(s).');

        return self::SUCCESS;
    }
}
