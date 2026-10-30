<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cagnotte Plus de chaque mois (décision de Jim, 31.10.2026) : 50 % des
 * revenus nets de Plus, partagés entre les vendeurs selon les vues de
 * leurs vidéos incluses dans Plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plus_pool_months', function (Blueprint $table): void {
            $table->id();
            $table->char('month', 7)->unique()->comment('AAAA-MM, à l’heure de Zurich');
            $table->unsignedInteger('net_revenue_cents')->comment('Revenus de Plus payés ce mois, moins les frais du prestataire');
            $table->unsignedInteger('pool_cents')->comment('La moitié, à partager');
            $table->unsignedInteger('views')->comment('Vues comptées grâce à Plus ce mois');
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plus_pool_months');
    }
};
