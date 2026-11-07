<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier patient, les champs non servis (06.11.2026) : l'employé qui a
 * fait une pesée ou ajouté un document, gardé à la création (comme le
 * vaccin et la visite). Sans clinique (le propriétaire dans son espace),
 * aucun. Les documents déjà ajoutés reprennent l'employé de leur compte
 * (`ajoute_par`) dans l'organisation qui les a faits ; les anciennes
 * pesées restent sans auteur : rien ne le disait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesees', function (Blueprint $table): void {
            $table->foreignId('employe_id')->nullable()->after('patient_id')->comment('Qui a pesé l’animal')->constrained('employes')->nullOnDelete();
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('employe_id')->nullable()->after('ajoute_par')->comment('Qui a ajouté le document au dossier')->constrained('employes')->nullOnDelete();
        });

        DB::table('documents')
            ->whereNotNull('ajoute_par')
            ->whereNotNull('source_organisation_id')
            ->whereNull('employe_id')
            ->update(['employe_id' => DB::table('employes')
                ->select('employes.id')
                ->whereColumn('employes.user_id', 'documents.ajoute_par')
                ->whereColumn('employes.organisation_id', 'documents.source_organisation_id')
                ->limit(1)]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employe_id');
        });
        Schema::table('pesees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employe_id');
        });
    }
};
