<?php

use App\Enums\Espece;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File des urgences en attente de placement sur le planning.
     * L'entrée est retirée quand l'urgence est casée (le RDV créé fait
     * foi) — docs/data-model.md §3.
     */
    public function up(): void
    {
        Schema::create('urgences_attente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name');
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name');
            $table->string('reason');
            $table->unsignedTinyInteger('triage')->comment('1–3, 3 = vital');
            $table->unsignedSmallInteger('duree_minutes');
            $table->dateTime('arrivee_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('urgences_attente');
    }
};
