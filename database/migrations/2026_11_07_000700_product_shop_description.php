<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La boutique sur le site web de la clinique (choix B de Jim, 03.11.2026) :
 * une phrase pour présenter l'article dans la vitrine, réglée depuis la
 * Boutique de la clinique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->string('shop_description', 160)->nullable()->comment('Phrase de la vitrine, montrée au public');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropColumn('shop_description');
        });
    }
};
