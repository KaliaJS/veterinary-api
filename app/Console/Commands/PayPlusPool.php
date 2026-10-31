<?php

namespace App\Console\Commands;

use App\Services\Videos\PlusPool;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** La cagnotte Plus du mois passé (31.10.2026) : calculée une fois, versée dès 10 CHF ou en fin d'année. */
class PayPlusPool extends Command
{
    protected $signature = 'videos:plus-pool {month? : AAAA-MM, le mois passé par défaut}';

    protected $description = 'Calcule et verse la cagnotte Plus des vidéos de formation';

    public function handle(PlusPool $pool): int
    {
        $argument = $this->argument('month');
        $month = is_string($argument)
            ? CarbonImmutable::createFromFormat('!Y-m', $argument, 'Europe/Zurich')
            : CarbonImmutable::now('Europe/Zurich')->subMonthNoOverflow()->startOfMonth();
        if ($month === null) {
            $this->error('Mois illisible : AAAA-MM.');

            return self::FAILURE;
        }
        // Un mois pas fini figerait une cagnotte incomplète
        if ($month->addMonthNoOverflow() > CarbonImmutable::now('Europe/Zurich')) {
            $this->error('Ce mois n’est pas encore fini.');

            return self::FAILURE;
        }
        // Un mois manqué (planificateur arrêté le 1er) se rattrape au passage suivant, dans l'ordre
        if (! is_string($argument)) {
            foreach ($pool->missingMonths($month) as $missed) {
                $done = $pool->run($missed);
                $this->info($done->month.' (rattrapé) : cagnotte de '.number_format($done->pool_cents / 100, 2).' CHF pour '.$done->views.' vues.');
            }
        }
        $row = $pool->run($month);
        $this->info($row->month.' : cagnotte de '.number_format($row->pool_cents / 100, 2).' CHF pour '.$row->views.' vues.');

        return self::SUCCESS;
    }
}
