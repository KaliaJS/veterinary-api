<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Le rôle de modérateur de la plateforme ne se donne que d'ici, jamais
 * depuis l'application : `php artisan plateforme:moderateur a@b.ch`
 * (--retirer pour l'enlever).
 */
class DonnerModerateur extends Command
{
    protected $signature = 'plateforme:moderateur {email} {--retirer : Retire le rôle}';

    protected $description = 'Donne (ou retire) le rôle de modérateur de la plateforme Veterinary à un compte';

    public function handle(): int
    {
        $compte = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if (! $compte) {
            $this->error('Aucun compte à cette adresse.');

            return self::FAILURE;
        }

        $compte->forceFill(['est_moderateur' => ! $this->option('retirer')])->save();
        $this->info($compte->name.($compte->est_moderateur ? ' est modérateur de la plateforme.' : ' n’est plus modérateur.'));

        return self::SUCCESS;
    }
}
