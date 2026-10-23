<?php

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\MotifRefusDemande;
use App\Enums\StatutDemandeRendezVous;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Le compte qui l’a déposée (espace client)')->constrained()->nullOnDelete();
            $table->string('patient_name');
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name');
            $table->string('email')->nullable()->comment('Demande sans compte : pour répondre au propriétaire');
            $table->string('telephone', 40)->nullable()->comment('Demande sans compte');
            $table->string('reason');
            $table->boolean('urgence')->default(false)->comment('Le propriétaire dit que c’est urgent');
            $table->text('symptomes')->nullable()->comment('Ce que le propriétaire voit, pour une urgence');
            $table->string('souhait')->comment('Souhait horaire libre (« Aujourd’hui après 16:00 »)');
            $table->timestamp('debut_souhaite')->nullable()->comment('L’heure choisie dans le calendrier de la prise de rendez-vous');
            $table->boolean('teleconsultation')->default(false);
            $table->enum('mode', array_column(ModeDemandeRendezVous::cases(), 'value'));
            $table->unsignedSmallInteger('duree_minutes');
            $table->enum('statut', array_column(StatutDemandeRendezVous::cases(), 'value'))->default(StatutDemandeRendezVous::ATraiter->value);
            $table->json('creneaux_proposes')->nullable()->comment('Débuts proposés par la clinique (UTC), le propriétaire en choisit un');
            $table->enum('motif_refus', array_column(MotifRefusDemande::cases(), 'value'))->nullable();
            $table->text('message')->nullable()->comment('Mot de la clinique joint à la réponse');
            // Clé étrangère posée avec la table rendez_vous (000021), créée après celle-ci
            $table->unsignedBigInteger('rendez_vous_id')->nullable()->comment('Le rendez-vous créé à l’acceptation');
            $table->timestamp('traitee_le')->nullable();
            $table->string('jeton', 64)->nullable()->unique()->comment('Empreinte (SHA-256) du lien de suivi d’une demande sans compte, ou du retour d’un paiement');
            $table->text('jeton_chiffre')->nullable()->comment('Le lien lui-même, chiffré, pour les e-mails suivants (23.10.2026)');
            $table->timestamp('jeton_expire_le')->nullable()->comment('Le lien ne sert plus après');
            $table->unsignedInteger('acompte_centimes')->nullable()->comment('Acompte demandé pour réserver');
            $table->string('acompte_intention_id')->nullable()->unique()->comment('Intention de paiement Stripe de l’acompte');
            $table->string('acompte_stripe_compte_id')->nullable()->comment('Compte Stripe de la société qui a reçu l’acompte (remboursement), 23.10.2026');
            $table->timestamp('acompte_paye_le')->nullable();
            $table->timestamp('acompte_rembourse_le')->nullable();
            $table->unsignedInteger('acompte_frais_centimes')->nullable()->comment('Frais du paiement retenus par le prestataire');
            $table->unsignedInteger('acompte_rembourse_centimes')->nullable()->comment('Montant rendu (tout, ou moins les frais)');
            $table->timestamp('annulation_demandee_le')->nullable()->comment('Le propriétaire demande l’annulation d’un rendez-vous avec acompte');
            $table->string('annulation_motif', 500)->nullable();
            $table->string('annulation_decision', 12)->nullable()->comment('complet | hors_frais | sans | refusee');
            $table->timestamp('annulation_traitee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_rendez_vous');
    }
};
