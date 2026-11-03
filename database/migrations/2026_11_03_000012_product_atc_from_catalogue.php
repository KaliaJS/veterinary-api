<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Dossier patient (03.11.2026) : les articles déjà en stock reprennent une
 * fois leur code ATC du catalogue Swissmedic, par leur code-barres. Rien à
 * défaire : les codes repris restent modifiables sur l'article.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('produits:atc-depuis-catalogue');
    }

    public function down(): void {}
};
