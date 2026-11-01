<?php

use App\Models\Vaccination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une injection saisie à la main qui nomme des maladies connues (« Rage »,
 * « Leucose (FeLV) », « Typhus / Coryza ») est renouvelée quand ces
 * maladies sont couvertes (vérification du 01.11.2026) : `renouvelee` se
 * recalcule pour chaque animal qui a des injections.
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
