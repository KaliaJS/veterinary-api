<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue des médicaments vétérinaires autorisés en Suisse (16.10.2026) :
     * - `produits_veterinaires` : un produit par numéro d'autorisation
     *   Swissmedic, importé du flux OGD mensuel (jamais supprimé : `retire_le`
     *   quand il disparaît du flux) ; `especes` dérivé du code ATCvet et du
     *   nom ; les vaccins et autres immunologiques portent `categorie` ;
     * - `produit_emballages` : les emballages autorisés, et leur GTIN (code-
     *   barres) apporté par la base d'articles Refdata ;
     * - `valences` : les maladies couvertes, en français (données curées) ;
     *   `produit_valence` : lesquelles pour chaque produit ;
     * - `vaccin_schemas` : schéma d'emploi curé par produit (âge minimal,
     *   primo-vaccination, rappel, voie) ;
     * - `catalogue_imports` : journal de chaque import (empreinte du fichier,
     *   comptes, résultat).
     * Les vaccinations se relient au produit et gardent une photographie des
     * valences et le lot, la péremption, la voie et le site d'injection.
     */
    public function up(): void
    {
        Schema::create('produits_veterinaires', function (Blueprint $table) {
            $table->id();
            $table->string('numero_autorisation', 12)->comment('N° d autorisation Swissmedic');
            $table->string('nom')->comment('Désignation complète Swissmedic (allemand)');
            $table->string('nom_court')->comment('Marque (« Nobivac DHPPi »)');
            $table->string('forme', 12)->nullable()->comment('Code de forme galénique Swissmedic');
            $table->string('atc_code', 12)->nullable();
            $table->string('categorie', 16)->comment('vaccin | immunologique | medicament');
            $table->string('titulaire')->nullable();
            $table->string('statut', 4)->nullable()->comment('Z autorisé, B provisoire…');
            $table->date('premiere_autorisation_le')->nullable();
            $table->date('expire_le')->nullable();
            $table->text('indication')->nullable()->comment('Texte Swissmedic (allemand)');
            $table->boolean('liberation_lot')->default(false);
            $table->string('categorie_remise', 4)->nullable();
            $table->json('especes')->nullable()->comment('Codes Espece cibles');
            $table->json('voies')->nullable()->comment('Codes des voies d administration Swissmedic');
            $table->date('source_le')->nullable()->comment('Date d export du flux qui l a vu en dernier');
            $table->date('retire_le')->nullable()->comment('Absent du flux depuis');
            $table->timestamps();

            $table->index('atc_code');
            $table->index('categorie');
            $table->index('nom_court');
            $table->unique('numero_autorisation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits_veterinaires');
    }
};
