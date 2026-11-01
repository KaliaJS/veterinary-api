<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une remise au détenteur se fait sur une ordonnance choisie dans l'app,
 * plus un numéro tapé à la main (accord de Jim, 01.11.2026) : la ligne du
 * registre garde le lien, et le numéro figé (`ordonnance_numero`) au cas où
 * l'ordonnance serait annulée dans sa fenêtre d'annulation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->foreignId('ordonnance_id')->nullable()->after('signataire_nom')->constrained('ordonnances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ordonnance_id');
        });
    }
};
