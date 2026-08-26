<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une ligne par champ modifié ; `batch_id` groupe les champs d'un
     * même geste (un déplacement = starts_at + ligne dans le même lot).
     * Les valeurs sont en JSON : elles portent des dates, des chaînes ou
     * l'objet de l'entrée « création ». Immuable — pas d'updated_at.
     */
    public function up(): void
    {
        Schema::create('rendez_vous_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('batch_id');
            $table->string('champ');
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur')->nullable();
            $table->dateTime('created_at');

            $table->index(['rendez_vous_id', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous_audits');
    }
};
