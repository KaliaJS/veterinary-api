<?php

namespace App\Console\Commands;

use App\Models\FactureProprietaire;
use App\Models\OrganisationFacture;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chaque minute : les paiements envoyés à Stripe sans réponse sont renvoyés
 * tels quels, jusqu'à la réponse (recommandation de Stripe, 26.10.2026). Le
 * client n'a pas à garder la page ouverte.
 */
class VerifierPaiements extends Command
{
    protected $signature = 'abonnements:verifier-paiements';

    protected $description = 'Renvoie à Stripe les paiements restés sans réponse';

    public function handle(AbonnementSaas $abonnements): int
    {
        foreach ([OrganisationFacture::query(), FactureProprietaire::query()] as $requete) {
            $requete->where('statut', 'a_payer')->whereNotNull('metadonnees->demande_en_suspens')
                ->each(function ($facture) use ($abonnements): void {
                    try {
                        $abonnements->verifierPaiementEnSuspens($facture);
                    } catch (Throwable $e) {
                        // Une facture en échec n'empêche pas les autres
                        Log::warning('Vérification d’un paiement impossible.', ['facture' => $facture->numero, 'erreur' => $e->getMessage()]);
                        $abonnements->signalerCleRefusee($e);
                    }
                });
        }

        return self::SUCCESS;
    }
}
