<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les préférences d'une personne de l'équipe, pour une clinique (demande de
 * Jim, 04.11.2026) : ses outils favoris, dans l'ordre où elle les a ajoutés,
 * et son choix de masquer les autres outils. Gardées sur le serveur pour les
 * retrouver sur un autre appareil et sur l'iPhone. Sans ligne : aucun favori,
 * rien de masqué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->json('tool_favorites')->nullable()->comment('Clés des outils favoris, dans l’ordre des ajouts');
            $table->boolean('hide_non_favorite_tools')->default(false)->comment('Masquer les outils qui ne sont pas en favoris');
            $table->timestamps();

            $table->unique(['user_id', 'clinique_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_preferences');
    }
};
