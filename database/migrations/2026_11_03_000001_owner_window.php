<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fenêtre propriétaire, proposition A (choix de Jim, 03.11.2026) : le
 * contact d'urgence, la note de l'accueil, les types de rappels coupés,
 * la langue, les nouvelles de la clinique et l'accord photos sur la fiche
 * (client sans compte) ; les types de rappels coupés dans l'espace (avec
 * un compte, ses choix font foi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('emergency_contact')->nullable()->comment('Contact en cas d’urgence (nom, lien)');
            $table->string('emergency_phone')->nullable();
            $table->text('note')->nullable()->comment('Note de l’accueil, visible par l’équipe seulement');
            $table->json('muted_reminders')->nullable()->comment('Types de rappels coupés : vaccines, appointments, checkups, quotes (client sans compte)');
            $table->string('language', 5)->nullable()->comment('Langue des messages (client sans compte)');
            $table->boolean('accepts_news')->default(false)->comment('Nouvelles de la clinique (client sans compte)');
            $table->timestamp('photo_consent_at')->nullable()->comment('Accord pour les photos de ses animaux sur le site');
        });
        Schema::table('preferences_proprietaire', function (Blueprint $table): void {
            $table->json('muted_reminders')->nullable()->comment('Types de rappels coupés par le propriétaire');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['emergency_contact', 'emergency_phone', 'note', 'muted_reminders', 'language', 'accepts_news', 'photo_consent_at']);
        });
        Schema::table('preferences_proprietaire', function (Blueprint $table): void {
            $table->dropColumn('muted_reminders');
        });
    }
};
