<?php

use App\Enums\StatutCommandeBoutique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commandes de la boutique en ligne (docs/data-model.md §14),
     * rattachées à la clinique de RETRAIT. Le client peut être une
     * fiche (espace client) ou un nom libre ; la remise (`done`) sort
     * le stock de la clinique.
     */
    public function up(): void
    {
        Schema::create('commandes_boutique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete()
                ->comment('Clinique de retrait');
            $table->string('numero', 20)->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_nom');
            $table->enum('statut', array_column(StatutCommandeBoutique::cases(), 'value'))
                ->default(StatutCommandeBoutique::APreparer->value);
            $table->dateTime('passee_le');
            $table->dateTime('retiree_le')->nullable();
            $table->timestamps();

            $table->index(['clinique_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_boutique');
    }
};
