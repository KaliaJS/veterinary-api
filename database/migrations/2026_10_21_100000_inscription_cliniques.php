<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Inscription des cliniques (20.10.2026) : un vétérinaire inscrit son
 * organisation (société, première clinique) depuis son compte. Son GLN
 * est cherché dans MedReg, le registre public des professions médicales :
 * vétérinaire au même nom, l'organisation est vérifiée aussitôt ; sinon
 * la plateforme vérifie à la main (e-mail avec Valider et Refuser).
 * L'organisation n'ouvre rien tant qu'elle n'est pas vérifiée et payée.
 *
 * Les organisations existantes sont déjà connues : vérifiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('gln', 13)->nullable()->unique()->after('email')
                ->comment('GLN du vétérinaire, retrouvé dans MedReg ou validé à la main');
            $table->unsignedInteger('medreg_id')->nullable()->after('gln');
            $table->timestamp('gln_verifie_le')->nullable()->after('medreg_id');
        });

        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('verification', 12)->default('en_attente')->after('nom');
            $table->string('verification_raison', 24)->nullable()->after('verification')
                ->comment('Pourquoi la vérification se fait à la main : introuvable, nom_different, gln_deja_utilise, medreg_indisponible');
            $table->string('verification_gln', 13)->nullable()->after('verification_raison')
                ->comment('GLN donné à l’inscription');
            $table->text('verification_note')->nullable()->after('verification_gln')
                ->comment('Motif d’un refus, montré à l’organisation');
            $table->timestamp('verifiee_le')->nullable()->after('verification_note');
            $table->foreignId('inscrite_par')->nullable()->after('verifiee_le')->constrained('users')->nullOnDelete();
        });

        DB::table('organisations')->update(['verification' => 'verifiee', 'verifiee_le' => now()]);
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inscrite_par');
            $table->dropColumn(['verification', 'verification_raison', 'verification_gln', 'verification_note', 'verifiee_le']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['gln']);
            $table->dropColumn(['gln', 'medreg_id', 'gln_verifie_le']);
        });
    }
};
