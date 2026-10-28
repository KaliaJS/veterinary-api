<?php

namespace App\Console\Commands;

use App\Services\Rappels\RappelsProprietaires;
use Illuminate\Console\Command;

/** Rappels par e-mail aux propriétaires : vaccins et contrôles dus, en journée (28.10.2026). */
class EnvoyerRappels extends Command
{
    protected $signature = 'rappels:envoyer';

    protected $description = 'Envoie par e-mail les rappels de vaccins et de contrôles dus, à l’heure de chaque clinique';

    public function handle(RappelsProprietaires $rappels): int
    {
        $partis = $rappels->envoyerLesRappelsDus();
        $this->info($partis.' rappel'.($partis > 1 ? 's' : '').' envoyé'.($partis > 1 ? 's' : '').'.');

        return self::SUCCESS;
    }
}
