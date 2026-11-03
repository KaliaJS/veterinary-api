<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation, la ronde (03.11.2026) : un soin du plan de soins peut
 * être l'administration d'un traitement (treatment_id, dose), donné ou
 * « non donné » avec sa raison. Le nom de qui l'a noté est gardé tel qu'au
 * jour du geste. Une administration n'existe qu'une fois par heure prévue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->foreignId('treatment_id')->nullable()->after('sejour_id')->constrained('stay_treatments')->nullOnDelete();
            $table->string('dose')->nullable()->after('acte');
            $table->string('done_by_name')->nullable()->after('fait_par_id')->comment('Qui l’a donné ou fait, tel qu’au jour du geste');
            $table->dateTime('skipped_at')->nullable()->after('done_by_name')->comment('Noté « non donné » ou « non fait »');
            $table->foreignId('skipped_by_id')->nullable()->after('skipped_at')->constrained('users')->nullOnDelete();
            $table->string('skipped_by_name')->nullable()->after('skipped_by_id');
            $table->string('skip_reason')->nullable()->after('skipped_by_name');

            $table->unique(['treatment_id', 'jour', 'prevu_a']);
        });
    }

    public function down(): void
    {
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->dropUnique(['treatment_id', 'jour', 'prevu_a']);
            $table->dropConstrainedForeignId('treatment_id');
            $table->dropConstrainedForeignId('skipped_by_id');
            $table->dropColumn(['dose', 'done_by_name', 'skipped_at', 'skipped_by_name', 'skip_reason']);
        });
    }
};
