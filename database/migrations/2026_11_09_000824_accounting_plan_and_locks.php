<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'export comptable (point e, 09.11.2026) : le plan comptable de chaque
 * société (vide : celui du plan PME suisse, App\Support\PlanComptable),
 * et les mois remis à la fiduciaire, verrouillés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('societes', function (Blueprint $table) {
            $table->json('plan_comptable')->nullable()->comment('Comptes et codes TVA de l’export, sinon ceux du plan PME');
        });

        Schema::create('accounting_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7)->comment('AAAA-MM, remis à la fiduciaire');
            $table->timestamp('locked_at');
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Les écritures telles que remises : un plan comptable changé ensuite ne les change pas
            $table->json('entries')->nullable();
            // Rouvert : la trace reste (qui, quand) ; verrouillé de nouveau, le verrou repart
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['societe_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_locks');
        Schema::table('societes', fn (Blueprint $table) => $table->dropColumn('plan_comptable'));
    }
};
