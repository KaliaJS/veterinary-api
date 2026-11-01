<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le vétérinaire responsable des stupéfiants de la clinique (décision de
 * Jim, 01.11.2026) : désigné par un administrateur parmi ses vétérinaires,
 * seul à viser le registre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->foreignId('responsable_stupefiants_id')->nullable()->after('urgences_a_valider')
                ->comment('Vétérinaire responsable des stupéfiants, seul à viser le registre')->constrained('employes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cliniques', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsable_stupefiants_id');
        });
    }
};
