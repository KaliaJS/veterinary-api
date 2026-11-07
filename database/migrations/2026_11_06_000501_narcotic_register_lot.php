<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le lot inscrit au registre des stupéfiants (décision de Jim,
 * 04.11.2026) : un stupéfiant suit son registre, son lot est celui de la
 * ligne. Le numéro est figé à l'inscription, comme les noms : le registre
 * reste lisible même quand le lot a été écarté depuis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->foreignId('lot_id')->nullable()->after('produit_id')->comment('Le lot de la ligne ; vide : sans lot connu, ou écarté depuis')->constrained()->nullOnDelete();
            $table->string('numero_lot', 30)->nullable()->after('lot_id')->comment('Numéro du lot, figé à l’inscription');
        });
    }

    /** Le registre est un journal légal : ses lots inscrits ne s'effacent pas */
    public function down(): void
    {
        if (DB::table('mouvements_stupefiants')->whereNotNull('lot_id')->orWhereNotNull('numero_lot')->exists()) {
            throw new RuntimeException('Le lot inscrit au registre des stupéfiants ne se défait pas : des lignes le portent.');
        }
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lot_id');
            $table->dropColumn('numero_lot');
        });
    }
};
