<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le message au propriétaire sur une analyse validée, rédigé par l'IA
 * (09.11.2026) : en mots simples, chaque valeur expliquée en une phrase,
 * l'évolution seulement sur 3 mesures en 2 ans (sens calculé par le code).
 * Chaque phrase porte sa source ; le vétérinaire relit, signe, envoie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('analyse_id')->unique()->constrained('analyses')->cascadeOnDelete();
            $table->string('status', 16)->default('empty')->comment('empty, writing, draft, failed, signed');
            $table->string('error', 32)->nullable()->comment('writings, budget, writer');
            $table->text('draft')->nullable()->comment('Chiffré : sources, phrases et leurs sources');
            $table->text('sections')->nullable()->comment('Chiffré : le texte relu, par partie');
            $table->json('edited_sections')->nullable();
            $table->unsignedTinyInteger('writings')->default(0);
            $table->timestamp('written_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('signed_by_name')->nullable();
            $table->timestamp('sent_at')->nullable()->comment('Envoyé au propriétaire');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_summaries');
    }
};
