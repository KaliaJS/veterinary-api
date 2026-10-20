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
        Schema::create('vaccin_schemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits_veterinaires')->cascadeOnDelete();
            $table->unsignedSmallInteger('age_min_semaines')->nullable();
            $table->unsignedTinyInteger('primo_injections')->nullable()->comment('Injections de la primo-vaccination');
            $table->unsignedSmallInteger('primo_intervalle_semaines')->nullable();
            $table->unsignedSmallInteger('rappel_premier_mois')->nullable()->comment('Premier rappel après la primo, si différent');
            $table->unsignedSmallInteger('rappel_mois')->nullable()->comment('Rappel courant ; vide : pas de rappel régulier');
            $table->string('voie', 40)->nullable();
            $table->text('remarques')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccin_schemas');
    }
};
