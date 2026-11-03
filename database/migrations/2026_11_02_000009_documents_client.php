<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche à qui un document était destiné (02.11.2026) : un consentement
 * signé par le propriétaire d'alors. Le propriétaire suivant de l'animal ne
 * le voit pas (décision de Jim). Les consentements déjà signés reçoivent la
 * fiche actuelle de l'animal, comme les en-têtes figés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('patient_id')->comment('Fiche à qui le document était destiné (consentement signé)')->constrained('clients')->nullOnDelete();
        });

        DB::table('documents')
            ->where('type', 'consentement')
            ->whereNull('client_id')
            ->update(['client_id' => DB::table('patients')->select('client_id')->whereColumn('patients.id', 'documents.patient_id')]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
