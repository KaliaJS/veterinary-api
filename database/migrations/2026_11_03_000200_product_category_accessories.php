<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La boutique ne vend pas que des médicaments (Jim, 03.11.2026) : la famille
 * « Accessoires et jouets » (acc). La colonne est un enum MySQL figé à sa
 * création ; une base neuve la crée déjà avec « acc » (CategorieProduit::cases()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->enum('categorie', ['med', 'ali', 'soin', 'apara', 'phyto', 'mat', 'acc'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            // Impossible tant qu'un article est de la famille « acc » : il la garde
            $table->enum('categorie', ['med', 'ali', 'soin', 'apara', 'phyto', 'mat'])->change();
        });
    }
};
