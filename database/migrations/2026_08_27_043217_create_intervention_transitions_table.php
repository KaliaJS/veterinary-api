<?php

use App\Enums\StatutIntervention;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des transitions du cycle opératoire (`log` du front) —
     * immuable comme les audits de rendez-vous : création seule, une
     * ligne par passage de statut, avec l'auteur du geste.
     */
    public function up(): void
    {
        Schema::create('intervention_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->enum('statut', array_column(StatutIntervention::cases(), 'value'));
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['intervention_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_transitions');
    }
};
