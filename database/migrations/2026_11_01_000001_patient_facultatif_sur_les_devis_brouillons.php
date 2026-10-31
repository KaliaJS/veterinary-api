<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un brouillon de devis se commence sans patient (01.11.2026, demande
     * de Jim) : le patient n'est exigé qu'à l'envoi (CycleDevisController).
     */
    public function up(): void
    {
        Schema::table('devis', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('devis', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable(false)->change();
        });
    }
};
