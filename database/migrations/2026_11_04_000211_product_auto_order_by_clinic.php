<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La commande automatique ne se règle plus pour toute l'organisation : elle
 * est passée par clinique (product_order_settings, 04.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->dropColumn(['is_auto_order', 'reorder_up_to']);
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table): void {
            $table->boolean('is_auto_order')->default(false);
            $table->unsignedSmallInteger('reorder_up_to')->nullable();
        });
        DB::table('product_order_settings')->where('is_auto_order', true)->get()
            ->each(fn ($row) => DB::table('produits')->where('id', $row->produit_id)->update(['is_auto_order' => true, 'reorder_up_to' => $row->reorder_up_to]));
    }
};
