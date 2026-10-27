<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accès sur invitation (décision de Jim, 27.10.2026) : les adresses qui
     * peuvent créer un compte tant que `ACCES_SUR_INVITATION` est vrai.
     * Adresse en minuscules ; `utilisee_le` : le compte a été créé.
     */
    public function up(): void
    {
        Schema::create('invitations_acces', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('note', 190)->nullable();
            $table->foreignId('invitee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('utilisee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations_acces');
    }
};
