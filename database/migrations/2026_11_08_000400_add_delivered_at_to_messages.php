<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les coches des messages façon WhatsApp (décision de Jim, 08.11.2026) :
 * une coche, envoyé ; deux grises, distribué (l'app du destinataire l'a
 * reçu) ; deux bleues, lu. `delivered_at` est posé quand l'app du
 * destinataire relit ses messages. Un message déjà lu a été distribué au
 * plus tard à sa lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_clientele', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('lu_le')->comment('Reçu par l’app du destinataire');
        });
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('read_at')->comment('Reçu par l’app du destinataire');
        });
        Schema::table('clinic_messages', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('read_at')->comment('Reçu par l’app du destinataire');
        });
        DB::table('messages_clientele')->whereNotNull('lu_le')->update(['delivered_at' => DB::raw('lu_le')]);
        DB::table('staff_messages')->whereNotNull('read_at')->update(['delivered_at' => DB::raw('read_at')]);
        DB::table('clinic_messages')->whereNotNull('read_at')->update(['delivered_at' => DB::raw('read_at')]);
    }

    public function down(): void
    {
        Schema::table('messages_clientele', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
        Schema::table('staff_messages', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
        Schema::table('clinic_messages', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
    }
};
