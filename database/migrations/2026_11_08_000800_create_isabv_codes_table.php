<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : les données de base de l'OSAV pour les logiciels de
 * cabinet (`/psw/getMasterData/*`), importées telles quelles : espèces et
 * catégories d'utilisation, clés de diagnostic (par espèce), fréquences
 * d'administration (par formulaire), voies d'administration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isabv_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 12)->comment('species | diagnosis | frequency | route');
            $table->string('species_code', 12)->default('')->comment('Clé de diagnostic : l’espèce à laquelle elle appartient ; vide sinon');
            $table->string('code', 24);
            $table->string('parent_code', 24)->nullable()->comment('Espèce parente, ou clé de diagnostic parente');
            $table->string('label_fr');
            $table->string('label_de')->nullable();
            $table->string('label_it')->nullable();
            $table->string('category', 8)->nullable()->comment('Fréquence : le formulaire (EH, EN, AV, OG, NOG, OGG)');
            $table->decimal('per_day', 10, 8)->nullable()->comment('Fréquence : administrations par jour (0,5 pour un jour sur deux)');
            $table->boolean('is_valid_for_consultations')->nullable()->comment('Espèce : comptée dans le nombre de consultations');
            $table->timestamps();

            $table->unique(['kind', 'species_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isabv_codes');
    }
};
