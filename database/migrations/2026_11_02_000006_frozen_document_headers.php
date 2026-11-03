<?php

use App\Support\FreezeExistingHeaders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'en-tête figé des pièces (02.11.2026, étape 0 de la fusion des dossiers) :
 * le destinataire et l'émetteur tels qu'à l'émission. Les pièces déjà
 * émises reçoivent une fois l'en-tête de la fiche actuelle (choix de Jim),
 * puis ne bougent plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Écrit table par table (pas en boucle) : l'analyse statique lit ainsi les colonnes
        Schema::table('factures', function (Blueprint $table): void {
            $table->json('recipient')->nullable()->comment('Nom et adresse du client à l’émission (figés)');
            $table->json('issuer')->nullable()->comment('En-tête de la clinique à l’émission : nom, adresse, société, IDE (figé)');
        });
        Schema::table('ordonnances', function (Blueprint $table): void {
            $table->json('recipient')->nullable()->comment('Nom et adresse du propriétaire à l’émission (figés)');
            $table->json('issuer')->nullable()->comment('En-tête de la clinique à l’émission : nom, adresse, société, IDE (figé)');
        });
        Schema::table('devis', function (Blueprint $table): void {
            $table->json('recipient')->nullable()->comment('Nom et adresse du client à l’envoi (figés)');
            $table->json('issuer')->nullable()->comment('En-tête de la clinique à l’envoi : nom, adresse, société, IDE (figé)');
        });

        app(FreezeExistingHeaders::class)->run();
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table): void {
            $table->dropColumn(['recipient', 'issuer']);
        });
        Schema::table('ordonnances', function (Blueprint $table): void {
            $table->dropColumn(['recipient', 'issuer']);
        });
        Schema::table('devis', function (Blueprint $table): void {
            $table->dropColumn(['recipient', 'issuer']);
        });
    }
};
