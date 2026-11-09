<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La convention Médvét (OMédV art. 10, annexe 1) et les stocks d'une
 * exploitation (art. 11 al. 2, art. 28 al. 2), 09.11.2026 : une convention
 * par espèce et par exploitation, d'au moins un an, avec sa catégorie ; ses
 * visites documentent les quatre points de l'annexe 1 ch. 1 ; les entrées
 * en stock, restitutions et destructions de l'exploitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medvet_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('species', 2);
            $table->string('category', 40)->comment('Catégorie de l’exploitation, annexe 1 ch. 2 (App\\Support\\Medvet)');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('vet_name', 120)->comment('Vétérinaire responsable technique (art. 10a)');
            $table->date('terminated_on')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'species']);
        });

        Schema::table('farm_visits', function (Blueprint $table) {
            $table->foreignId('medvet_agreement_id')->nullable()->constrained('medvet_agreements')->nullOnDelete();
            // Annexe 1 ch. 1 : à chaque visite de la convention, pour chaque espèce
            $table->text('health_status')->nullable();
            $table->text('problems')->nullable();
            $table->text('indications')->nullable();
            $table->text('medicine_use')->nullable();
        });

        Schema::create('farm_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12)->comment('entry | return | destruction (art. 28 al. 2)');
            $table->date('moved_on');
            $table->string('product_name', 255);
            $table->string('quantity', 80)->comment('En unités de conditionnement');
            $table->string('counterpart', 255)->comment('Le fournisseur, ou qui reprend');
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('purpose', 20)->nullable()->comment('Usage du stock (art. 11 al. 2)');
            $table->unsignedTinyInteger('months')->nullable()->comment('Durée couverte, en mois');
            $table->foreignId('medvet_agreement_id')->nullable()->constrained('medvet_agreements')->nullOnDelete();
            $table->foreignId('facture_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'moved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_stock_movements');
        Schema::table('farm_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medvet_agreement_id');
            $table->dropColumn(['health_status', 'problems', 'indications', 'medicine_use']);
        });
        Schema::dropIfExists('medvet_agreements');
    }
};
