<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un gros PDF reçu, glissé dans le dossier de l'animal (étape 3,
 * 09.11.2026) : Claude Haiku 4.5 propose la découpe (pièces, noms, sortes,
 * dates lus dans le PDF), le vétérinaire la corrige et la valide ; chaque
 * pièce devient un document du dossier. Le PDF reste dans le navigateur :
 * ici, seulement l'état et la proposition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('file_name')->nullable();
            $table->unsignedSmallInteger('pages_count');
            $table->string('status', 12)->default('splitting')->comment('splitting, ready, failed, done');
            $table->string('error', 32)->nullable()->comment('budget, splitter');
            $table->text('proposal')->nullable()->comment('Chiffré : les pièces proposées [{from, to, title, kind, date, source}]');
            $table->unsignedSmallInteger('documents_count')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_imports');
    }
};
