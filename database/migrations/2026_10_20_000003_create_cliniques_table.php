<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->string('raison_sociale')->nullable();
            $table->string('rue')->nullable();
            $table->string('npa', 4)->nullable();
            $table->string('ville')->nullable();
            $table->decimal('latitude', 8, 5)->nullable();
            $table->decimal('longitude', 8, 5)->nullable();
            $table->string('tel')->nullable();
            $table->string('email')->nullable();
            $table->string('fuseau_horaire')->default('Europe/Zurich');
            $table->unsignedTinyInteger('nombre_boxes')->default(0)->comment('Capacité d’hospitalisation');
            $table->boolean('affiche_veterinaires')->default(true)->comment('Annuaire public : liste des vétérinaires et de leurs spécialités, sinon les icônes seules');
            $table->string('photo_chemin')->nullable()->comment('Photo de la clinique (annuaire), posée par l’administrateur');
            $table->text('photo_apercu')->nullable()->comment('Aperçu flou de la photo, quelques centaines d’octets en data URI');
            $table->string('stripe_location_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliniques');
    }
};
