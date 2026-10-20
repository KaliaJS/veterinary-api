<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passkeys WebAuthn (web-auth/webauthn-lib en direct) :
     * `credential_id` en base64url, `donnees` = CredentialRecord JSON.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('credential_id');
            $table->json('donnees');
            $table->dateTime('dernier_usage_le')->nullable();
            $table->timestamps();

            $table->unique('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
