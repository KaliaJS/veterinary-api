<?php

namespace App\Console\Commands;

use App\Services\Purchasing\AutoBaskets;
use Illuminate\Console\Command;

/**
 * Les paniers automatiques (03.11.2026, revu le 04.11.2026) : jamais de
 * départ sans un avis au moins 12 heures avant ; partis au minimum ou à la
 * date butoir, sous le minimum la règle du fournisseur.
 */
class SendAutoBaskets extends Command
{
    protected $signature = 'orders:auto-send';

    protected $description = 'Paniers automatiques des fournisseurs : avis au moins 12 heures avant chaque départ, puis au départ (minimum atteint, ou date butoir selon la règle sous le minimum) envoi par e-mail ou par la centrale si PURCHASING_AUTO_SEND_API le permet ; fournisseur à la main ou centrale non permise : la clinique est seulement interrogée';

    public function handle(AutoBaskets $baskets): int
    {
        $sent = $baskets->run();
        $this->info("Paniers automatiques partis : {$sent}.");

        return self::SUCCESS;
    }
}
