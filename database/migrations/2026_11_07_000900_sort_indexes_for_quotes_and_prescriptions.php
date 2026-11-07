<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tri au-delà de 100 lignes (vérification du 07.11.2026) : les listes des
 * devis et des ordonnances se lisent par organisation, de la plus récente à
 * la plus ancienne (emis_le, signee_le), page après page : un index chacune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis', function (Blueprint $table): void {
            $table->index(['organisation_id', 'emis_le']);
        });
        Schema::table('ordonnances', function (Blueprint $table): void {
            $table->index(['organisation_id', 'signee_le']);
        });
    }

    public function down(): void
    {
        Schema::table('devis', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'emis_le']);
        });
        Schema::table('ordonnances', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'signee_le']);
        });
    }
};
