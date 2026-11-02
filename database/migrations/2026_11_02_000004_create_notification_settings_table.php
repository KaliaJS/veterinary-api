<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les notifications du téléphone, réglées par chacun (demande de Jim,
     * 02.11.2026) : les sortes coupées, la nuit (oui ou non, et ses heures,
     * rien de fixé d'avance pour de bon), les urgences même la nuit.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('disabled_types');
            $table->boolean('quiet_enabled')->default(true);
            $table->string('quiet_from', 5)->default('22:00');
            $table->string('quiet_to', 5)->default('07:00');
            $table->boolean('urgent_at_night')->default(true);
            $table->string('timezone', 64)->default('Europe/Zurich');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
