<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les prises annulées avant l'historique (sixième vérification,
 * 03.11.2026) : leur annulation y entre, pour que le rapport et la ronde
 * la gardent si la prise est notée de nouveau.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sejour_soins')->whereNotNull('cancelled_at')->orderBy('id')->each(function (object $soin): void {
            // Rejouée, elle n'écrit jamais deux fois la même annulation (septième vérification)
            $isThere = DB::table('stay_care_cancellations')->where('sejour_soin_id', $soin->id)->where('cancelled_at', $soin->cancelled_at)->exists();
            if ($isThere) {
                return;
            }
            DB::table('stay_care_cancellations')->insert([
                'sejour_soin_id' => $soin->id,
                'given_at' => $soin->fait_le,
                'given_by_name' => $soin->done_by_name,
                'dose_amount' => $soin->dose_amount,
                'dose_unit' => $soin->dose_unit,
                'late_noted_at' => $soin->late_noted_at,
                'cancelled_at' => $soin->cancelled_at,
                'cancelled_by_id' => $soin->cancelled_by_id,
                'cancelled_by_name' => $soin->cancelled_by_name,
                'cancelled_late' => (bool) $soin->cancelled_late,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Rien à défaire : la table disparaît avec la migration précédente
    }
};
