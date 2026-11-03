<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Médicaments à la dose (décision de Jim, 03.11.2026). Un produit dit ce
 * que contient une unité de stock (un flacon de 20 ml : 20, « ml ») ; le
 * stock d'une clinique garde le reste de l'unité entamée ; chaque prise
 * donnée sort sa dose, et n'ouvre une nouvelle unité que quand l'entamée
 * est vide. Le journal garde la dose à côté des unités ouvertes. La prise
 * et le traitement gardent la dose par prise, dans l'unité du produit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->string('dose_unit', 12)->nullable()->after('unite')->comment('Unité de dose : ml, cp, UI…');
            $table->decimal('content_per_unit', 10, 3)->nullable()->after('dose_unit')->comment('Doses contenues dans une unité de stock');
        });
        Schema::table('stocks', function (Blueprint $table): void {
            $table->decimal('opened_rest', 10, 3)->default(0)->after('quantite')->comment('Doses restant dans l’unité entamée');
        });
        Schema::table('mouvements_stock', function (Blueprint $table): void {
            $table->decimal('dose', 10, 3)->nullable()->after('quantite')->comment('Dose sortie, dans l’unité de dose du produit');
        });
        Schema::table('stay_treatments', function (Blueprint $table): void {
            $table->decimal('dose_amount', 10, 3)->nullable()->after('dose')->comment('Dose par prise, dans l’unité du produit');
        });
        Schema::table('sejour_soins', function (Blueprint $table): void {
            $table->decimal('dose_amount', 10, 3)->nullable()->after('dose')->comment('Dose de la prise, dans l’unité du produit');
        });
    }

    public function down(): void
    {
        Schema::table('sejour_soins', fn (Blueprint $table) => $table->dropColumn('dose_amount'));
        Schema::table('stay_treatments', fn (Blueprint $table) => $table->dropColumn('dose_amount'));
        Schema::table('mouvements_stock', fn (Blueprint $table) => $table->dropColumn('dose'));
        Schema::table('stocks', fn (Blueprint $table) => $table->dropColumn('opened_rest'));
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn(['dose_unit', 'content_per_unit']));
    }
};
