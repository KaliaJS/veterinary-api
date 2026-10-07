<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le site web ne recopie plus l'équipe ni les contacts : l'équipe vient
 * des employés (le site ne retient que les personnes masquées), le
 * téléphone et l'e-mail viennent des cliniques (le site décide seulement
 * de les afficher). Photo d'employé (privée, servie par un nom
 * imprévisible) et e-mail de clinique ajoutés à la source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->string('photo_chemin')->nullable()->after('email');
        });

        Schema::table('cliniques', function (Blueprint $table) {
            $table->string('email')->nullable()->after('tel');
        });

        Schema::table('sites_web', function (Blueprint $table) {
            $table->dropColumn(['equipe', 'email']);
        });

        Schema::table('sites_web', function (Blueprint $table) {
            $table->json('equipe_masquee')->nullable()->after('services')->comment('Ids des employés cachés du site');
            $table->boolean('afficher_tel')->default(true)->after('tel_urgences');
            $table->boolean('afficher_email')->default(true)->after('afficher_tel');
        });
    }

    public function down(): void
    {
        Schema::table('sites_web', function (Blueprint $table) {
            $table->dropColumn(['equipe_masquee', 'afficher_tel', 'afficher_email']);
            $table->json('equipe')->nullable();
            $table->string('email')->nullable();
        });

        Schema::table('cliniques', function (Blueprint $table) {
            $table->dropColumn('email');
        });

        Schema::table('employes', function (Blueprint $table) {
            $table->dropColumn('photo_chemin');
        });
    }
};
