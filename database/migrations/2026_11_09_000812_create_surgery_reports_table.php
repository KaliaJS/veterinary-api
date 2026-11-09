<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le rapport de bloc (C, choix de Jim, 09.11.2026) : un par intervention.
 * Le brouillon de l'IA garde ses sources numérotées (l'écran du bloc, les
 * lignes des notes, la dictée) et la source de chaque phrase ; les
 * rubriques sont le texte relu par le vétérinaire. Validé, il est figé ;
 * des compléments datés et signés s'y ajoutent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intervention_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('empty')->comment('empty, writing, draft, failed, validated');
            $table->string('error', 32)->nullable()->comment('writings, budget, writer');
            $table->text('draft')->nullable()->comment('Chiffré : sources, phrases et leurs sources, doutes');
            $table->text('sections')->nullable()->comment('Chiffré : le texte relu, par rubrique');
            $table->unsignedTinyInteger('writings')->default(0)->comment('Rédactions demandées à l’IA');
            $table->timestamp('written_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('validated_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('surgery_report_complements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('surgery_report_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->foreignId('by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_report_complements');
        Schema::dropIfExists('surgery_reports');
    }
};
