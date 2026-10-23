<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clôtures de caisse (Z) — par CLINIQUE : la caisse est un tiroir
     * physique, même si les factures suivent l'organisation
     * (docs/data-model.md §6). Les factures couvertes pointent la
     * clôture (factures.cloture_caisse_id) ; l'écart non nul exige une
     * justification, contrôlée à la création.
     */
    public function up(): void
    {
        Schema::create('clotures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->string('numero', 20);
            $table->dateTime('arretee_le');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_tpe', 10, 2)->default(0);
            $table->decimal('total_twint', 10, 2)->default(0);
            $table->decimal('total_especes', 10, 2)->default(0);
            $table->decimal('fond', 8, 2)->comment('Fond de caisse initial');
            $table->decimal('especes_comptees', 10, 2);
            $table->decimal('ecart', 8, 2)->comment('comptées − (fond + espèces encaissées)');
            $table->decimal('depot', 10, 2)->comment('Dépôt en banque');
            $table->decimal('nouveau_fond', 8, 2);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['clinique_id', 'arretee_le']);
            // Numéro unique par clinique : chaque organisation numérote à
            // partir de 1, et sa séquence (SequenceNumerotation) garantit
            // l'unicité entre ses cliniques
            $table->unique(['clinique_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse');
    }
};
