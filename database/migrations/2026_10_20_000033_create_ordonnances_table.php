<?php

use App\Enums\StatutOrdonnance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des ordonnances (docs/data-model.md §12). L'ordonnance
     * suit le patient — NIVEAU ORGANISATION — mais garde la clinique de
     * l'acte : c'est son stock qui sort à la signature.
     * `ordonnance_precedente_id` relie un renouvellement à l'ordonnance
     * qu'il clôt (l'annulation du renouvellement la réactive).
     */
    public function up(): void
    {
        Schema::create('ordonnances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->string('numero', 20);
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('employe_id')->constrained()->restrictOnDelete();
            $table->date('signee_le');
            $table->unsignedTinyInteger('renouvellements')->default(0)->comment('Renouvellements restants (0–3)');
            $table->enum('statut', array_column(StatutOrdonnance::cases(), 'value'))->default('active');
            $table->text('note')->nullable()->comment('Note pour le propriétaire');
            $table->foreignId('ordonnance_precedente_id')->nullable()->constrained('ordonnances')->nullOnDelete();
            $table->timestamps();

            // Numéro unique dans l'organisation : chaque organisation
            // numérote à partir de 1 (SequenceNumerotation)
            $table->unique(['organisation_id', 'numero']);
            $table->index(['organisation_id', 'statut']);
            $table->index(['patient_id', 'signee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordonnances');
    }
};
