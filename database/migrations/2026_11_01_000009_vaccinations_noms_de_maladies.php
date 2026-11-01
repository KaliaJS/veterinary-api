<?php

use App\Models\Vaccination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un nom libre qui nomme des maladies connues (« Rage », « Carré · Rage »)
 * prend leurs valences (vérification du 01.11.2026) : `renouvelee` se
 * recalcule pour les animaux qui ont une injection sans produit ni
 * valences photographiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vaccinations')->whereNull('produit_id')->whereNull('valences')->distinct()->orderBy('patient_id')->pluck('patient_id')
            ->each(fn (int $patientId) => Vaccination::recalculerRenouvellements($patientId));
    }

    public function down(): void
    {
        // Rien à défaire : la colonne se recalcule à la prochaine injection de l'animal
    }
};
