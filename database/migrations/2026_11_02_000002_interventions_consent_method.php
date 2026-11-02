<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comment le consentement a été recueilli (demande de Jim, 02.11.2026) :
     * signé à la clinique, signé à distance, accord oral ou urgence. Pour
     * un accord oral, `consent_details` garde qui l'a donné, comment et ce
     * qui a été expliqué ; le vétérinaire qui en prend la responsabilité
     * est `consentement_recueilli_par_id`, l'heure `consentement_signe_le`.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('consent_method', 16)->nullable()->after('consentement_signe_le');
            $table->json('consent_details')->nullable()->after('consent_method');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['consent_method', 'consent_details']);
        });
    }
};
