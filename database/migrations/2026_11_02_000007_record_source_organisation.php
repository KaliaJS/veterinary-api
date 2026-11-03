<?php

use App\Support\BackfillRecordSources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La provenance des actes (02.11.2026, étape 0 de la fusion des dossiers) :
 * l'organisation qui a fait chaque pesée, document, visite, vaccin et relevé.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Écrit table par table (pas en boucle) : l'analyse statique lit ainsi les colonnes
        Schema::table('pesees', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });
        Schema::table('visites', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });
        Schema::table('releves_ophtalmologiques', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
        });

        app(BackfillRecordSources::class)->run();
    }

    public function down(): void
    {
        Schema::table('pesees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
        Schema::table('visites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
        Schema::table('releves_ophtalmologiques', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_organisation_id');
        });
    }
};
