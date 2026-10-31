<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La catégorie et l'affiche d'une vidéo de formation (31.10.2026, écran
 * « Vidéos » à la Netflix demandé par Jim). L'affiche est une image de la
 * vidéo ou une photo, réécrite en WebP à quelques largeurs dans son propre
 * dossier ; la route publique la retrouve par le jeton de ce dossier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('category', 30)->nullable()->index()->after('audience')->comment('VideoCategory : la rangée du catalogue');
            $table->string('poster_token', 40)->nullable()->unique()->after('category')->comment('Dossier de l’affiche (video-posters/{jeton}), imprévisible : la route publique la retrouve par lui');
            $table->text('poster_preview')->nullable()->after('poster_token')->comment('Aperçu minuscule en data URL, flouté à l’écran le temps que l’affiche arrive');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['category', 'poster_token', 'poster_preview']);
        });
    }
};
