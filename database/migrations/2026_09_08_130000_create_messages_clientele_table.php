<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Messagerie clinique ↔ propriétaire (écran « Messages » de l'espace
 * client, docs/data-model.md §15) : un fil par (compte, clinique).
 * `auteur` dit qui écrit ; `employe_id` nomme l'auteur côté clinique ;
 * `lu_le` est posé quand le destinataire ouvre le fil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_clientele', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('auteur', ['proprietaire', 'clinique']);
            $table->text('texte');
            $table->timestamp('lu_le')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'clinique_id', 'created_at'], 'messages_clientele_fil_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_clientele');
    }
};
