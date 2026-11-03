<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non facturée, sans ambiguïté (huitième vérification, 03.11.2026) : la
 * prise le sait au moment où elle est notée, sous le verrou du séjour,
 * au lieu de comparer deux heures à la seconde près. Les prises déjà
 * notées le reprennent d'une comparaison stricte : dans la même seconde
 * que la sortie, elle compte comme facturée (jamais annulable à tort).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->boolean('is_unbilled')->default(false)->comment('Notée après la sortie : la facture ne la compte pas');
        });
        DB::table('sejour_soins')->join('sejours', 'sejours.id', '=', 'sejour_soins.sejour_id')
            ->where('sejour_soins.fait', true)->whereNotNull('sejours.sorti_le')->whereNotNull('sejour_soins.late_noted_at')
            ->whereColumn('sejour_soins.late_noted_at', '>', 'sejours.sorti_le')
            ->pluck('sejour_soins.id')
            ->chunk(500)->each(fn ($ids) => DB::table('sejour_soins')->whereIn('id', $ids->all())->update(['is_unbilled' => true]));
    }

    public function down(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dropColumn('is_unbilled');
        });
    }
};
