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
        Schema::create('produit_emballages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits_veterinaires')->cascadeOnDelete();
            $table->string('numero_emballage', 12)->comment('N° d autorisation à 8 chiffres (produit + emballage)');
            $table->string('sequence', 4)->nullable();
            $table->string('taille')->nullable();
            $table->string('unite', 12)->nullable();
            $table->string('remarque')->nullable();
            $table->string('statut', 4)->nullable();
            $table->date('retire_le')->nullable();
            $table->string('gtin', 14)->nullable()->comment('Code-barres (Refdata)');
            $table->string('nom_fr')->nullable()->comment('Désignation Refdata en français');
            $table->date('commercialise_le')->nullable();
            $table->date('arrete_le')->nullable();
            $table->timestamps();

            $table->index('gtin');
            $table->unique(['produit_id', 'numero_emballage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_emballages');
    }
};
