<?php

use App\Enums\Civilite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Niveau organisation, pas clinique (docs/data-model.md §2).
     * `user_id` : compte espace client (0..1) — conditionne le canal
     * d'envoi des devis (portail vs papier).
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('civilite', array_column(Civilite::cases(), 'value'))->nullable();
            $table->string('prenom');
            $table->string('nom');
            $table->string('tel')->nullable();
            $table->string('email')->nullable();
            $table->string('rue')->nullable();
            $table->string('npa', 4)->nullable();
            $table->string('localite')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
