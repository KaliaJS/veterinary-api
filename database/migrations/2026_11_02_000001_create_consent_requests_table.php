<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consentement envoyé pour signature (demande de Jim, 02.11.2026) : un
     * lien personnel par SMS ou par e-mail, ou un avis dans l'espace du
     * propriétaire. Le jeton n'est gardé que par son empreinte ; un nouvel
     * envoi annule le précédent. `signed_ip` et `signed_user_agent` gardent
     * la trace de la signature à distance.
     */
    public function up(): void
    {
        Schema::create('consent_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('intervention_id')->constrained('interventions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('channels')->comment('sms, email, space');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('sent_by_employee_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->timestamps();

            $table->index(['intervention_id', 'cancelled_at', 'signed_at'], 'consent_requests_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_requests');
    }
};
