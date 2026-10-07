<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apparence du site web : mode clair ou sombre, couleur libre (quand
 * `couleur` = « perso »), style des titres, coins, et logo de
 * l'organisation (fichier privé servi par une route publique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->string('mode', 10)->default('clair')->after('couleur');
            $table->string('couleur_perso', 7)->nullable()->after('mode')->comment('#rrggbb quand couleur = perso');
            $table->string('police', 12)->default('moderne')->after('couleur_perso');
            $table->string('coins', 10)->default('arrondis')->after('police');
            $table->string('logo_chemin')->nullable()->after('coins')->comment('Disque local (privé)');
        });
    }

    public function down(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->dropColumn(['mode', 'couleur_perso', 'police', 'coins', 'logo_chemin']);
        });
    }
};
