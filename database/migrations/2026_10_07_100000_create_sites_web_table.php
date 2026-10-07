<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site web public d'une organisation (accueil avec horaires de chaque
 * clinique et actualités, services, équipe, contact) : un site par
 * organisation, qui présente toutes ses cliniques. Les blocs de contenu,
 * courts et ordonnés, vivent en JSON. Adresses, téléphones et horaires
 * ne sont pas dupliqués : lus sur les cliniques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites_web', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->unique()->comment('Adresse publique /site/{slug}');
            $table->boolean('est_publie')->default(false);
            $table->string('couleur', 20)->default('sauge');
            $table->string('accroche', 160)->nullable();
            $table->text('presentation')->nullable();
            $table->string('email')->nullable();
            $table->string('tel_urgences')->nullable();
            $table->json('services')->comment('[{titre, description}]');
            $table->json('equipe')->comment('[{nom, role, bio, clinique}]');
            $table->json('actualites')->comment('[{titre, texte, date}]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites_web');
    }
};
