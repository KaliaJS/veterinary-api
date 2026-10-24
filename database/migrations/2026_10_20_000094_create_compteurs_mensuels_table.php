<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compteurs du mois de la plateforme (24.10.2026) : ce qui a pu être
     * facturé par un fournisseur sans laisser de trace ailleurs (essais
     * envoyés à Deepgram sans réponse, rédactions reprises chez Anthropic).
     * En base, pas dans le cache : un vidage du cache ne les efface pas.
     */
    public function up(): void
    {
        Schema::create('compteurs_mensuels', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 60);
            $table->char('mois', 7)->comment('AAAA-MM, heure suisse');
            $table->bigInteger('valeur')->default(0);
            $table->timestamps();
            $table->unique(['cle', 'mois']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compteurs_mensuels');
    }
};
