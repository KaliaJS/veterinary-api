<?php

use App\Enums\StatutVerificationVeterinaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('gln', 13)->nullable()->comment('GLN du vétérinaire, retrouvé dans MedReg ou validé à la main');
            $table->unsignedInteger('medreg_id')->nullable();
            $table->timestamp('gln_verifie_le')->nullable();
            $table->enum('verification_veterinaire', array_column(StatutVerificationVeterinaire::cases(), 'value'))->nullable()->comment('Vérification manuelle par la plateforme (badge) : en_attente, verifie, refuse');
            $table->timestamp('verification_veterinaire_le')->nullable()->comment('Dernière demande ou décision');
            $table->boolean('est_moderateur')->default(false)->comment('Modérateur de la plateforme Veterinary : donné par commande, jamais par l’application');
            $table->char('code_employe', 6)->nullable()->unique()->comment('Code court que la personne donne pour être embauchée (« K7F3QZ », affiché « K7F-3QZ »)');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('formule', 12)->default('gratuit')->comment('gratuit | plus');
            $table->char('apple_compte', 36)->nullable();
            $table->string('apple_abonnement_id')->nullable();
            $table->timestamp('apple_fin')->nullable();
            $table->boolean('apple_renouvelle')->nullable();
            $table->string('stripe_client_id')->nullable();
            $table->string('stripe_moyen_paiement_id')->nullable();
            $table->string('moyen_paiement')->nullable()->comment('Libellé masqué « Visa •••• 4242 · exp. 08/28 »');
            $table->date('carte_expire_le')->nullable()->comment('Fin du mois d’expiration de la carte enregistrée');
            $table->date('carte_alertee_le')->nullable()->comment('Dernière alerte : la carte expire avant le prochain prélèvement');
            $table->string('statut_abonnement', 12)->nullable()->comment('actif | impaye | incomplet | resilie ; null : jamais abonné');
            $table->date('periode_fin')->nullable();
            $table->date('periode_debut')->nullable();
            $table->unsignedTinyInteger('ancre_jour')->nullable()->comment('Jour du mois du renouvellement (le dernier jour d’un mois plus court)');
            $table->unsignedTinyInteger('tentatives')->default(0)->comment('Prélèvements refusés depuis le dernier paiement');
            $table->timestamp('prochaine_tentative_le')->nullable();
            $table->date('resilie_le')->nullable()->comment('Retour à Gratuit à cette date (fin de la période payée)');
            $table->timestamps();

            $table->unique('apple_compte');
            $table->unique('email');
            $table->unique('gln');
            $table->unique('stripe_client_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');

            $table->index('last_activity');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
