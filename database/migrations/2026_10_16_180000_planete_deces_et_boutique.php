<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeu de la planète (16.10.2026) : la date de décès d'un animal (une petite
 * tombe à son nom sur la planète ; les rappels de la clinique s'arrêtent),
 * et les objets achetés dans la boutique de la planète, une fois chacun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->date('decede_le')->nullable()->after('ne_le');
        });

        Schema::create('achats_planete', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('article', 20);
            $table->string('stripe_facture_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achats_planete');
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('decede_le');
        });
    }
};
