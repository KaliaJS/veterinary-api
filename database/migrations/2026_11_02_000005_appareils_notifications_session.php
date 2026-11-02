<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un téléphone (ou une montre) est lié à la session qui l'a enregistré
     * (02.11.2026) : la session fermée (déconnexion, « Déconnecter les
     * autres appareils », accès coupé), l'appareil disparaît avec elle et ne
     * reçoit plus rien ; une session expirée ne reçoit plus rien non plus.
     */
    public function up(): void
    {
        Schema::table('appareils_notifications', function (Blueprint $table) {
            $table->foreignId('personal_access_token_id')->nullable()->after('user_id')->constrained('personal_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appareils_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
        });
    }
};
