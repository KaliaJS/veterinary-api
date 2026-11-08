<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : ce que la déclaration demande et que la fiche n'a
 * pas encore. L'animal : son espèce ou sa catégorie d'utilisation au sens de
 * l'OSAV (un lapin de compagnie ou de rente, une vache laitière), proposée
 * selon l'espèce. Le détenteur d'animaux de rente : le n° BDTA et l'adresse
 * de son unité d'élevage (O-SI ABV, annexe ch. 2.1.2). L'article : la
 * préparation de la liste de l'OSAV, quand le code-barres ne la donne pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('isabv_species', 12)->nullable()->comment('Espèce ou catégorie d’utilisation SI ABV, sinon proposée selon l’espèce');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('holding_number', 32)->nullable()->comment('N° BDTA (ou SI ABV) de l’unité d’élevage');
            $table->string('holding_address')->nullable()->comment('Adresse de l’unité d’élevage, si autre que celle du détenteur');
        });
        Schema::table('produits', function (Blueprint $table): void {
            $table->string('isabv_preparation_code', 12)->nullable()->comment('Préparation SI ABV choisie à la main, sinon par le code-barres');
        });
    }

    public function down(): void
    {
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn('isabv_preparation_code'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['holding_number', 'holding_address']));
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('isabv_species'));
    }
};
