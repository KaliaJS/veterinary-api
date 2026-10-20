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
        Schema::create('valences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('nom')->comment('Maladie, en français');
            $table->string('nom_court', 40)->comment('Pour les badges');
            $table->string('agent')->nullable()->comment('Agent pathogène');
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valences');
    }
};
