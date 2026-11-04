<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nouvel article B (03.11.2026) : « Commander automatiquement sous le seuil »
 * et jusqu'où remonter le stock ; la photo de la vitrine, sur le modèle de
 * celle des animaux (fichier privé au nom imprévisible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->boolean('is_auto_order')->default(false)->comment('Sous le seuil, entre seul dans le panier automatique de sa première source');
            $table->unsignedSmallInteger('reorder_up_to')->nullable()->comment('Quantité visée par le panier automatique ; vide : deux fois le seuil');
            $table->string('photo_chemin')->nullable()->comment('Photo de la vitrine, disque privé');
            $table->string('photo_fichier', 64)->nullable()->index()->comment('Nom du fichier de la photo, sans dossier ni extension : la route publique la retrouve par lui');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropIndex(['photo_fichier']);
            $table->dropColumn(['is_auto_order', 'reorder_up_to', 'photo_chemin', 'photo_fichier']);
        });
    }
};
