<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient, les champs non servis (06.11.2026) : la robe de
 * l'animal (sa couleur, ses marques), saisie sur la fiche, et son
 * vétérinaire référent, un employé de l'organisation choisi sur la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('coat', 120)->nullable()->after('race')->comment('La robe : couleur et marques de l’animal');
            $table->foreignId('referring_vet_id')->nullable()->comment('Le vétérinaire référent, un employé de l’organisation')->constrained('employes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('referring_vet_id');
            $table->dropColumn('coat');
        });
    }
};
