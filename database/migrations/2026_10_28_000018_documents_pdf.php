<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents PDF du dossier (demande de Jim, 28.10.2026) : un rapport ou le
 * compte rendu d'un confrère, gardé tel quel. Le propriétaire ne le voit
 * que si la clinique le partage ; les documents existants restent visibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('nom_fichier')->nullable()->after('chemin');
            $table->unsignedBigInteger('taille_octets')->nullable()->after('nom_fichier')->comment('PDF ajouté au dossier ; compte dans le stockage');
            $table->boolean('visible_proprietaire')->default(true)->after('taille_octets')->comment('Montré dans l’espace du propriétaire');
            $table->foreignId('ajoute_par')->nullable()->after('signataire')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ajoute_par');
            $table->dropColumn(['nom_fichier', 'taille_octets', 'visible_proprietaire']);
        });
    }
};
