<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rappels par SMS (décisions de Jim, 28.10.2026) : la case « Rappels par
 * SMS » de la fiche d'un client sans compte ; la fenêtre « Terminer la
 * configuration » du propriétaire (choix des rappels fait, fenêtre vue
 * au premier accès, masquée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('rappels_sms')->default(false)->after('rappels_email')->comment('Cochée par la clinique pour un client sans compte');
        });

        Schema::table('preferences_proprietaire', function (Blueprint $table): void {
            $table->timestamp('rappels_choisis_le')->nullable();
            $table->timestamp('configuration_vue_le')->nullable();
            $table->timestamp('configuration_masquee_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('rappels_sms'));
        Schema::table('preferences_proprietaire', fn (Blueprint $table) => $table->dropColumn(['rappels_choisis_le', 'configuration_vue_le', 'configuration_masquee_le']));
    }
};
