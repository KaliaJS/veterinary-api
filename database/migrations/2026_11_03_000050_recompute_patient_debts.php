<?php

use App\Services\Facturation\PatientDebts;
use Illuminate\Database\Migrations\Migration;

/**
 * La dette des animaux n'était jamais calculée depuis la facturation
 * (03.11.2026) : elle l'est désormais à chaque facture ; les valeurs
 * existantes sont recalculées une fois depuis les factures dues.
 */
return new class extends Migration
{
    public function up(): void
    {
        PatientDebts::refreshAll();
    }

    public function down(): void
    {
        // Rien à défaire : la dette se recalcule depuis les factures
    }
};
