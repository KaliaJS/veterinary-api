<?php

namespace App\Console\Commands;

use App\Services\Transcription\FacturationTranscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Facture les minutes de transcription du mois écoulé. Planifiée le 1er du mois. */
class FacturerTranscriptions extends Command
{
    protected $signature = 'transcriptions:facturer {--mois= : mois à facturer (AAAA-MM), le mois écoulé par défaut}';

    protected $description = 'Facture aux organisations les minutes de transcription d’un mois';

    public function handle(FacturationTranscriptions $facturation): int
    {
        $mois = $this->option('mois')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('mois'), 'Europe/Zurich')
            : CarbonImmutable::now('Europe/Zurich')->startOfMonth()->subMonthNoOverflow();

        if (! $mois) {
            $this->error('Mois invalide : AAAA-MM attendu.');

            return self::FAILURE;
        }

        $facturations = $facturation->facturerMois($mois);
        $this->info(count($facturations).' organisation(s) facturée(s) pour '.$mois->format('Y-m').'.');

        return self::SUCCESS;
    }
}
