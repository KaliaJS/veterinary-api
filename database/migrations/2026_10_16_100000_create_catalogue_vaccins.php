<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits_veterinaires', function (Blueprint $table): void {
            $table->id();
            $table->string('numero_autorisation', 12)->unique()->comment('N° d autorisation Swissmedic');
            $table->string('nom')->comment('Désignation complète Swissmedic (allemand)');
            $table->string('nom_court')->index()->comment('Marque (« Nobivac DHPPi »)');
            $table->string('forme', 12)->nullable()->comment('Code de forme galénique Swissmedic');
            $table->string('atc_code', 12)->nullable()->index();
            $table->string('categorie', 16)->index()->comment('vaccin | immunologique | medicament');
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
        });

        Schema::create('produit_emballages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits_veterinaires')->cascadeOnDelete();
            $table->string('numero_emballage', 12)->comment('N° d autorisation à 8 chiffres (produit + emballage)');
            $table->string('sequence', 4)->nullable();
            $table->string('taille')->nullable();
            $table->string('unite', 12)->nullable();
            $table->string('remarque')->nullable();
            $table->string('statut', 4)->nullable();
            $table->date('retire_le')->nullable();
            $table->string('gtin', 14)->nullable()->index()->comment('Code-barres (Refdata)');
            $table->string('nom_fr')->nullable()->comment('Désignation Refdata en français');
            $table->date('commercialise_le')->nullable();
            $table->date('arrete_le')->nullable();
            $table->timestamps();

            $table->unique(['produit_id', 'numero_emballage']);
        });

        Schema::create('valences', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('nom')->comment('Maladie, en français');
            $table->string('nom_court', 40)->comment('Pour les badges');
            $table->string('agent')->nullable()->comment('Agent pathogène');
            $table->timestamps();
        });

        Schema::create('produit_valence', function (Blueprint $table): void {
            $table->foreignId('produit_id')->constrained('produits_veterinaires')->cascadeOnDelete();
            $table->foreignId('valence_id')->constrained('valences')->cascadeOnDelete();
            $table->primary(['produit_id', 'valence_id']);
        });

        Schema::create('vaccin_schemas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('produit_id')->unique()->constrained('produits_veterinaires')->cascadeOnDelete();
            $table->unsignedSmallInteger('age_min_semaines')->nullable();
            $table->unsignedTinyInteger('primo_injections')->nullable()->comment('Injections de la primo-vaccination');
            $table->unsignedSmallInteger('primo_intervalle_semaines')->nullable();
            $table->unsignedSmallInteger('rappel_premier_mois')->nullable()->comment('Premier rappel après la primo, si différent');
            $table->unsignedSmallInteger('rappel_mois')->nullable()->comment('Rappel courant ; vide : pas de rappel régulier');
            $table->string('voie', 40)->nullable();
            $table->text('remarques')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::create('catalogue_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 16)->index()->comment('swissmedic | refdata');
            $table->string('fichier_hash', 64)->nullable();
            $table->date('export_le')->nullable();
            $table->string('statut', 16)->comment('reussi | identique | echec');
            $table->unsignedInteger('ajoutes')->default(0);
            $table->unsignedInteger('modifies')->default(0);
            $table->unsignedInteger('retires')->default(0);
            $table->text('message')->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->timestamps();
        });

        Schema::table('vaccinations', function (Blueprint $table): void {
            // (plus d échéance obligatoire : sérums, schémas par gestation…)
            $table->date('echeance_le')->nullable()->change();
            $table->foreignId('produit_id')->nullable()->after('patient_id')->constrained('produits_veterinaires')->nullOnDelete();
            $table->json('valences')->nullable()->after('nom')->comment('Codes des valences au moment de l injection');
            $table->string('gtin', 14)->nullable()->after('lot');
            $table->date('peremption_le')->nullable()->after('gtin');
            $table->string('voie', 40)->nullable()->after('peremption_le');
            $table->string('site_injection', 60)->nullable()->after('voie');
            $table->text('remarque')->nullable()->after('site_injection');
            $table->text('reaction')->nullable()->after('remarque')->comment('Effet indésirable observé');
        });
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('produit_id');
            $table->dropColumn(['valences', 'gtin', 'peremption_le', 'voie', 'site_injection', 'remarque', 'reaction']);
        });
        Schema::dropIfExists('catalogue_imports');
        Schema::dropIfExists('vaccin_schemas');
        Schema::dropIfExists('produit_valence');
        Schema::dropIfExists('valences');
        Schema::dropIfExists('produit_emballages');
        Schema::dropIfExists('produits_veterinaires');
    }
};
