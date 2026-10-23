<?php

namespace App\Console\Commands;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Enums\TypeAlerte;
use App\Mail\AlertesStockClinique;
use App\Models\Clinique;
use App\Models\Employe;
use App\Services\Alertes\AlertesClinique;
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

    public function handle(AlertesClinique $alertes, CalculateurPermissions $permissions): int
    {
        $envoyes = 0;

        Clinique::withoutGlobalScopes()->ouvertes()->with('societe.organisation')->each(function (Clinique $clinique) use ($alertes, $permissions, &$envoyes): void {
            $stock = $alertes->stock($clinique);
            if ($stock === []) {
                return;
            }

            $destinataires = Employe::withoutGlobalScopes()
                ->whereHas('cliniques', fn ($requete) => $requete->whereKey($clinique->id))
                ->where('acces_suspendu', false)
                ->with(['user', 'role', 'derogations'])
                ->get()
                ->filter(fn (Employe $employe) => $employe->recoitAlertesEmail(TypeAlerte::Stock)
                    && in_array(DomainePermission::Stocks->value, $permissions->pour($employe), true))
                ->map(fn (Employe $employe) => $employe->user?->email)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($destinataires !== []) {
                Mail::to($destinataires)->send(new AlertesStockClinique($clinique, $stock));
                $envoyes++;
            }
        });

        $this->info("Points du stock envoyés : {$envoyes}.");

        return self::SUCCESS;
    }
}
