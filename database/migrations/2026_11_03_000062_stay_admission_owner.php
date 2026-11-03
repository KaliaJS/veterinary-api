<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le séjour garde le propriétaire qui a confié l'animal (décision de Jim,
 * 03.11.2026, même règle que les rendez-vous de l'étape 0) : le rapport
 * part à lui, même si l'animal a changé de propriétaire depuis. Et le nom
 * de la personne qui a admis, tel qu'au jour de l'admission. Les séjours
 * existants reçoivent le propriétaire actuel de l'animal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejours', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('patient_id')->constrained('clients')->restrictOnDelete();
            $table->string('admitted_by_name')->nullable()->after('cree_par_id');
        });

        DB::table('sejours')->whereNull('client_id')->orderBy('id')->each(function (object $sejour): void {
            DB::table('sejours')->where('id', $sejour->id)->update([
                'client_id' => DB::table('patients')->where('id', $sejour->patient_id)->value('client_id'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sejours', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('admitted_by_name');
        });
    }
};
