<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site web public d'une organisation (accueil avec horaires de chaque
     * clinique et actualités, services, équipe, contact) : un site par
     * organisation, qui présente toutes ses cliniques. Les blocs de contenu,
     * courts et ordonnés, vivent en JSON. Adresses, téléphones et horaires
     * ne sont pas dupliqués : lus sur les cliniques.
     */
    public function up(): void
    {
        Schema::create('sites_web', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->comment('Adresse publique /site/{slug}');
            $table->string('domaine')->nullable();
            $table->string('domaine_statut', 10)->nullable();
            $table->string('domaine_erreur')->nullable();
            $table->string('domaine_fournisseur_id')->nullable();
            $table->timestamp('domaine_verifie_le')->nullable();
            $table->boolean('est_publie')->default(false);
            $table->string('couleur', 20)->default('sauge');
            $table->string('mode', 10)->default('clair');
            $table->string('couleur_perso', 7)->nullable()->comment('#rrggbb quand couleur = perso');
            $table->string('police', 12)->default('moderne');
            $table->string('coins', 10)->default('arrondis');
            $table->string('logo_chemin')->nullable()->comment('Disque local (privé)');
            $table->string('accroche', 160)->nullable();
            $table->text('presentation')->nullable();
            $table->string('tel_urgences')->nullable();
            $table->boolean('afficher_tel')->default(true);
            $table->boolean('afficher_email')->default(true);
            $table->json('services')->comment('[{titre, description}]');
            $table->json('equipe_masquee')->nullable()->comment('Ids des employés cachés du site');
            $table->json('actualites')->comment('[{titre, texte, date}]');
            $table->timestamps();

            $table->unique('domaine');
            $table->unique('organisation_id');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites_web');
    }
};
