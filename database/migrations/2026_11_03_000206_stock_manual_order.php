<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un article indisponible chez sa centrale, sans autre source (03.11.2026) :
 * il reste à commander à la main dans cette clinique, avec une alerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->timestamp('manual_order_since')->nullable();
            $table->string('manual_order_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropColumn(['manual_order_since', 'manual_order_reason']);
        });
    }
};
