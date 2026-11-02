<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Un animal, un seul compte (décision de Jim, 02.11.2026) : liste, en
 * lecture seule, les animaux dont la fiche à la clinique et la puce disent
 * deux comptes différents (données d'avant la règle). Sort en erreur s'il
 * y en a, pour qu'on le voie. Rien n'est modifié.
 */
class ListPetsWithTwoAccounts extends Command
{
    protected $signature = 'pets:two-accounts';

    protected $description = 'Liste (lecture seule) les animaux rattachés à deux comptes différents';

    public function handle(): int
    {
        $rows = DB::table('patients')
            ->join('clients', 'clients.id', '=', 'patients.client_id')
            ->whereNotNull('patients.proprietaire_id')
            ->whereNotNull('clients.user_id')
            ->whereColumn('patients.proprietaire_id', '!=', 'clients.user_id')
            ->orderBy('patients.id')
            ->get(['patients.id', 'patients.nom', 'patients.organisation_id', 'patients.proprietaire_id', 'clients.user_id as compte_fiche']);

        if ($rows->isEmpty()) {
            $this->info('Aucun animal n’a deux comptes.');

            return self::SUCCESS;
        }

        $this->warn($rows->count().($rows->count() > 1 ? ' animaux ont deux comptes :' : ' animal a deux comptes :'));
        $this->table(
            ['Animal', 'Nom', 'Organisation', 'Compte par la puce', 'Compte de la fiche'],
            $rows->map(fn ($r): array => [$r->id, $r->nom, $r->organisation_id, $r->proprietaire_id, $r->compte_fiche])->all(),
        );

        return self::FAILURE;
    }
}
