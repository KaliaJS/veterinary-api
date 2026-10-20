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
        Schema::create('catalogue_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->comment('swissmedic | refdata');
            $table->string('fichier_hash', 64)->nullable();
            $table->string('fichier_etag', 128)->nullable()->comment('ETag du serveur (If-None-Match au passage suivant)');
            $table->date('export_le')->nullable();
            $table->string('statut', 16)->comment('reussi | identique | echec');
            $table->unsignedInteger('ajoutes')->default(0);
            $table->unsignedInteger('modifies')->default(0);
            $table->unsignedInteger('retires')->default(0);
            $table->text('message')->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->timestamps();

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_imports');
    }
};
