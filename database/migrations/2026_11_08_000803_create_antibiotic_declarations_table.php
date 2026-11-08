<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : une déclaration d'antibiotiques à l'OSAV (O-SI ABV,
 * art. 4, al. 2 et annexe ch. 2), née d'une ordonnance ou d'un traitement
 * d'hospitalisation. Formulaire : EH (animal de compagnie ou équidé), EN
 * (animal de rente), AV (remise à titre de stocks), FZL (zoo, laboratoire).
 * Le numéro de prescription est un UUID, jamais réutilisé : une correction
 * part sous un nouveau numéro (`replaces_id`) après la désactivation de
 * l'ancienne (directives techniques, ch. 5.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antibiotic_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->char('recipe_number', 36)->unique()->comment('N° de prescription envoyé à l’OSAV (UUID)');
            $table->string('form', 4)->comment('EH | EN | AV | FZL');
            $table->string('status', 16)->comment('to_complete | ready | sent | rejected | replaced | deactivated');
            $table->nullableMorphs('source');
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employe_id')->nullable()->comment('Le vétérinaire qui a prescrit, remis ou utilisé')->constrained('employes')->nullOnDelete();
            $table->date('visit_on')->comment('Date de la consultation, ou de la remise');
            $table->string('species_code', 12)->nullable()->comment('Espèce (EH, FZL) ou catégorie d’utilisation (EN, AV), code de l’OSAV');
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->string('diagnosis_key', 24)->nullable();
            $table->string('diagnosis_description')->nullable();
            $table->boolean('is_antibiogram_done')->default(false);
            $table->unsignedInteger('animals_count')->nullable()->comment('Nombre d’animaux traités');
            $table->unsignedInteger('animals_total')->nullable()->comment('EN : animaux de cette catégorie dans l’exploitation (Ø par an)');
            $table->string('holding_number', 32)->nullable()->comment('EN, AV : n° BDTA (ou SI ABV) de l’unité d’élevage');
            $table->string('holding_address')->nullable()->comment('EN, AV, FZL : adresse de l’exploitation ou de l’établissement');
            $table->string('postcode', 8)->nullable()->comment('EH : NPA du détenteur');
            $table->string('identification')->nullable();
            $table->text('additional_instructions')->nullable();
            $table->unsignedSmallInteger('av_treatment_days')->nullable()->comment('AV : nombre de jours de traitement');
            $table->decimal('av_daily_dose', 12, 4)->nullable()->comment('AV : dosage par animal et par jour');
            $table->string('osav_uuid', 64)->nullable()->comment('Clé de l’entrée au protocole de communication de l’OSAV');
            $table->text('osav_message')->nullable()->comment('Message de l’OSAV (en français) pour une déclaration refusée');
            $table->json('ignored_fields')->nullable()->comment('Champs facultatifs écartés par l’OSAV');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->boolean('is_outcome_unknown')->default(false)->comment('Envoyée sans réponse lue : le protocole de l’OSAV dira si elle est arrivée');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('replaces_id')->nullable()->comment('La déclaration envoyée que celle-ci corrige')->constrained('antibiotic_declarations')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinique_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antibiotic_declarations');
    }
};
