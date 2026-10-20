<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue de prestations UNIQUE par organisation — la décision
     * ouverte de docs/data-model.md est tranchée : les deux catalogues
     * du front (ACTES de la caisse, FC_CATALOG de la fin de
     * consultation) fusionnent ici, prix de la caisse pour les
     * doublons. Le code des huit actes liés à un type de RDV est le
     * code du type (consult, vaccin, chir…).
     */
    public function up(): void
    {
        Schema::create('prestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->decimal('prix', 8, 2)->comment('Prix TTC CHF');
            $table->timestamps();

            $table->unique(['organisation_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestations');
    }
};
