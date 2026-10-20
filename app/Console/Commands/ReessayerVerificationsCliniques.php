<?php

namespace App\Console\Commands;

use App\Enums\VerificationOrganisation;
use App\Models\Organisation;
use App\Services\Inscription\InscriptionClinique;
use Illuminate\Console\Command;

/**
 * Cliniques inscrites pendant une panne de MedReg : la vérification
 * automatique est retentée (toutes les heures, une semaine durant). La
 * plateforme a déjà reçu l'e-mail pour décider à la main entre-temps.
 */
class ReessayerVerificationsCliniques extends Command
{
    protected $signature = 'inscriptions:reessayer';

    protected $description = 'Retente dans MedReg la vérification des cliniques inscrites pendant une panne';

    public function handle(InscriptionClinique $inscription): int
    {
        $verifiees = Organisation::query()
            ->where('verification', VerificationOrganisation::EnAttente)
            ->where('verification_raison', 'medreg_indisponible')
            ->where('created_at', '>=', now()->subWeek())
            ->get()
            ->filter(fn (Organisation $organisation): bool => $inscription->reessayer($organisation))
            ->count();

        $this->info($verifiees.' clinique(s) vérifiée(s).');

        return self::SUCCESS;
    }
}
