<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a demandé la mise en vente (31.10.2026, sixième vérification) : pour
 * une vidéo de clinique, la personne doit encore pouvoir la publier
 * (identité vérifiée, droit Organisation) quand la relecture s'achève.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->foreignId('publish_requested_by')->nullable()->after('publish_requested_at')->comment('Personne qui a demandé la mise en vente')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('publish_requested_by');
        });
    }
};
