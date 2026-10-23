<?php

use App\Enums\FormuleSaas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('verification', 12)->default('en_attente');
            $table->string('verification_raison', 24)->nullable()->comment('Pourquoi la vérification se fait à la main : introuvable, nom_different, gln_deja_utilise, medreg_indisponible');
            $table->string('verification_gln', 13)->nullable()->comment('GLN donné à l’inscription');
            $table->text('verification_note')->nullable()->comment('Motif d’un refus, montré à l’organisation');
            $table->timestamp('verifiee_le')->nullable();
            $table->foreignId('inscrite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('plan', array_column(FormuleSaas::cases(), 'value'))->default('cabinet');
            $table->string('formule_suivante', 12)->nullable()->comment('Formule moins chère choisie : appliquée au prochain renouvellement');
            $table->enum('cycle', ['mois', 'an'])->default('mois');
            $table->date('resilie_le')->nullable()->comment('Fin de l’abonnement résilié — réactivable jusque-là');
            $table->string('moyen_paiement')->nullable()->comment('Libellé masqué « Visa •••• 4242 »');
            $table->date('carte_expire_le')->nullable()->comment('Fin du mois d’expiration de la carte enregistrée');
            $table->date('carte_alertee_le')->nullable()->comment('Dernière alerte : la carte expire avant le prochain prélèvement');
            $table->string('stripe_client_id')->nullable();
            $table->string('stripe_moyen_paiement_id')->nullable();
            $table->string('statut_abonnement', 12)->nullable()->comment('actif | impaye | incomplet | resilie ; null : aucun abonnement payant');
            $table->date('periode_debut')->nullable();
            $table->unsignedTinyInteger('ancre_jour')->nullable()->comment('Jour du mois du renouvellement (le dernier jour d’un mois plus court)');
            $table->unsignedTinyInteger('tentatives')->default(0)->comment('Prélèvements refusés depuis le dernier paiement');
            $table->timestamp('prochaine_tentative_le')->nullable();
            $table->date('periode_fin')->nullable()->comment('Fin de la période payée : renouvellement, ou fin d’accès si résilié');
            $table->unsignedInteger('usage_emails')->default(0);
            $table->unsignedInteger('usage_sms')->default(0);
            $table->decimal('usage_stockage_go', 6, 1)->default(0);
            $table->unsignedInteger('quota_extra_emails')->default(0);
            $table->unsignedInteger('quota_extra_sms')->default(0);
            $table->unsignedInteger('quota_extra_stockage_go')->default(0);
            $table->unsignedInteger('plafond_transcription')->default(20000);
            $table->unsignedInteger('plafond_diagnostic')->default(5000)->comment('Plafond des suggestions diagnostiques par période, en centimes');
            $table->json('termes_transcription')->nullable();
            $table->json('premiers_pas')->nullable()->comment('Premiers pas d’une clinique neuve : étapes confirmées à la main, liste masquée');
            $table->timestamps();

            $table->unique('stripe_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
