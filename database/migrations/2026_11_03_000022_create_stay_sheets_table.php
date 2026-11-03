<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation, la ronde (03.11.2026) : la feuille d'un animal pour un
 * jour, de 06:00 à 06:00 le lendemain (`day` est le jour où elle s'ouvre).
 * Elle s'ouvre d'elle-même avec les traitements en cours ; passée, elle se
 * lit sans se modifier. Elle porte le rapport de la journée rédigé par
 * l'IA : à relire, puis signé (le nom du signataire est figé), puis envoyé
 * au propriétaire par sa messagerie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_id')->constrained('sejours')->cascadeOnDelete();
            $table->date('day')->comment('Jour de la feuille, de 06:00 à 06:00 le lendemain');
            $table->dateTime('opened_at');
            $table->string('report_status', 16)->default('none')->comment('none, drafting, to_review, signed, failed');
            $table->text('report_text')->nullable();
            $table->string('report_error', 24)->nullable()->comment('not_configured, budget, drafts, writing, empty');
            $table->unsignedTinyInteger('report_drafts')->default(0);
            $table->dateTime('report_drafted_at')->nullable();
            $table->dateTime('report_signed_at')->nullable();
            $table->foreignId('report_signed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_signed_by_name')->nullable();
            $table->dateTime('report_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['sejour_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_sheets');
    }
};
