<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les lots décomptés à chaque sortie, FEFO (décision de Jim, 04.11.2026) :
 * chaque ligne dit combien une sortie a pris dans un lot (négatif), ce
 * qu'une annulation y a rendu (positif, avec la prise qu'elle rend), ou ce
 * que la remise d'accord unique des lots a retiré. Le numéro et la
 * péremption du lot sont figés : un lot écarté depuis reste lisible.
 * L'animal et le client de la sortie sont gardés : on sait à qui un lot a
 * été donné ou vendu. Sans lot (`lot_number` vide) : du stock sans lot
 * connu. Immuable, création seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20)->comment('take : prise d’une sortie ; return : rendu d’une annulation ; entry : entrée dans le lot du registre ; reconciliation : remise d’accord avec le stock');
            $table->foreignId('mouvement_stock_id')->nullable()->comment('Le mouvement du journal ; vide pour la remise d’accord')->constrained('mouvements_stock')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->comment('Vide : stock sans lot connu, ou lot écarté depuis')->constrained()->nullOnDelete();
            $table->string('lot_number', 30)->nullable()->comment('Numéro du lot, figé');
            $table->date('expires_on')->nullable()->comment('Péremption du lot, figée');
            $table->integer('quantity')->comment('Signée : − pris dans le lot, + rendu au lot');
            $table->foreignId('returns_allocation_id')->nullable()->comment('La prise que ce rendu rend')->constrained('lot_allocations')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->comment('L’animal qui a reçu la sortie')->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->comment('Le client à qui elle a été vendue ou remise')->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['produit_id', 'clinique_id']);
            $table->index('patient_id');
            $table->index('client_id');
        });
    }

    /** La traçabilité des lots ne s'efface pas : refusé dès qu'une ligne existe */
    public function down(): void
    {
        if (Schema::hasTable('lot_allocations') && DB::table('lot_allocations')->exists()) {
            throw new RuntimeException('La traçabilité des lots (lot_allocations) ne se défait pas : la table a des lignes.');
        }
        Schema::dropIfExists('lot_allocations');
    }
};
