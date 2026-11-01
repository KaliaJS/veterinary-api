<?php

use App\Models\Vaccination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une parenthèse ne retire plus une maladie du nom d'une injection saisie à
 * la main (décision de Jim, 01.11.2026) : « Toux de chenil (Bordetella) »
 * garde la Bordetella et le parainfluenza. `renouvelee` se recalcule pour
 * chaque animal qui a des injections, avec la nouvelle règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vaccinations')->distinct()->orderBy('patient_id')->pluck('patient_id')
            ->each(fn (int $patientId) => Vaccination::recalculerRenouvellements($patientId));
    }

    public function down(): void
    {
        // Rien à défaire : la colonne se recalcule à la prochaine injection de l'animal
    }
};
