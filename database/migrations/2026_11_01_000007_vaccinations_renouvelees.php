<?php

use App\Models\Vaccination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une injection renouvelée (vérification du 01.11.2026) : la liste des
 * patients et le carnet comparaient les noms en SQL, l'envoi des rappels
 * les valences ; un vaccin refait avec un combiné restait « dû » d'un côté
 * seulement. `renouvelee` est tenu à jour d'après la règle unique
 * (EtatVaccinal::renouvelees) à chaque injection, et relu en SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->boolean('renouvelee')->default(false)->after('echeance_le');
            $table->index(['patient_id', 'renouvelee']);
        });

        DB::table('vaccinations')->distinct()->orderBy('patient_id')->pluck('patient_id')
            ->each(fn (int $patientId) => Vaccination::recalculerRenouvellements($patientId));
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropIndex(['patient_id', 'renouvelee']);
            $table->dropColumn('renouvelee');
        });
    }
};
