<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stockage de l'organisation (décision de Jim, 28.10.2026) : les DICOM se
 * comptent en direct ; les autres fichiers (photos, logo, consentements)
 * sont comptés chaque nuit à leur taille réelle et gardés ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->unsignedBigInteger('stockage_autres_octets')->default(0)->comment('Photos, logo, consentements : comptés chaque nuit (stockage:compter)');
            $table->dateTime('stockage_compte_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['stockage_autres_octets', 'stockage_compte_le']);
        });
    }
};
