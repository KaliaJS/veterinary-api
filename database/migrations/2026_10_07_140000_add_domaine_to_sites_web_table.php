<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine personnalisé d'un site (étape 1 : domaine déjà possédé par
 * l'organisation, pointé vers nous). Statut : attente (DNS pas encore
 * en place), actif (vérifié, servi), erreur (refus du fournisseur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->string('domaine')->nullable()->unique()->after('slug');
            $table->string('domaine_statut', 10)->nullable()->after('domaine');
            $table->string('domaine_erreur')->nullable()->after('domaine_statut');
            $table->string('domaine_fournisseur_id')->nullable()->after('domaine_erreur');
            $table->timestamp('domaine_verifie_le')->nullable()->after('domaine_fournisseur_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->dropUnique(['domaine']);
            $table->dropColumn(['domaine', 'domaine_statut', 'domaine_erreur', 'domaine_fournisseur_id', 'domaine_verifie_le']);
        });
    }
};
