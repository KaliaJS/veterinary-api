<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les constantes gardent qui les a relevées (03.11.2026) : elles comptent
 * dans « Soigné par ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sejour_constantes', function (Blueprint $table): void {
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by_name')->nullable()->comment('Qui les a relevées, tel qu’au jour du geste');
        });
    }

    public function down(): void
    {
        Schema::table('sejour_constantes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('by_id');
            $table->dropColumn('by_name');
        });
    }
};
