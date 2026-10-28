<?php

namespace App\Console\Commands;

use App\Enums\DomainePermission;
use App\Enums\TypeAlerte;
use App\Mail\AlertesStockClinique;
use App\Models\Clinique;
use App\Services\Alertes\AlertesClinique;
use App\Services\Alertes\DestinatairesAlertes;
use App\Support\TempsReel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Chaque matin (23.10.2026) : à chaque clinique qui a des alertes de stock
 * (produits sous le seuil, lots périmés ou qui périment bientôt), un e-mail
 * aux personnes de la clinique qui gèrent le stock, sauf à celles qui ont
 * coupé ces e-mails dans leurs réglages. Rien à signaler : pas d'e-mail.
 */
class EnvoyerAlertesStock extends Command
{
    protected $signature = 'alertes:stock';

    protected $description = 'Envoie le point du matin sur le stock (seuils, péremptions) aux cliniques concernées';

    public function handle(AlertesClinique $alertes, DestinatairesAlertes $lecteurs): int
    {
        $envoyes = 0;

        Clinique::withoutGlobalScopes()->ouvertes()->with('societe.organisation')->each(function (Clinique $clinique) use ($alertes, $lecteurs, &$envoyes): void {
            // Un jour nouveau (lots qui périment, période qui change) : les écrans relisent
            TempsReel::alertes($clinique->id);
            $stock = $alertes->stock($clinique);
            if ($stock === []) {
                return;
            }

            $destinataires = $lecteurs->pour($clinique, TypeAlerte::Stock, DomainePermission::Stocks);

            if ($destinataires !== []) {
                Mail::to($destinataires)->send(new AlertesStockClinique($clinique, $stock));
                $envoyes++;
            }
        });

        $this->info("Points du stock envoyés : {$envoyes}.");

        return self::SUCCESS;
    }
}
