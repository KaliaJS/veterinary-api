<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Abonnements et factures maison (20.10.2026) : Stripe ne sert plus qu'à
 * enregistrer les cartes et à les prélever (plus de Stripe Billing, 0,7 %
 * du volume économisé). Chaque abonné (organisation, compte propriétaire
 * pour Plus) a sa période en cours, renouvelée à la date anniversaire comme
 * chez Netflix ou Claude ; un paiement refusé est retenté quelques jours plus
 * tard. Les factures sont les nôtres (numéro MS-AAAA-NNNNN, lignes, période,
 * paiement), sans TVA : Meteora Systems Sàrl n'y est pas assujettie.
 *
 * `stripe_abonnement_id` est gardé le temps d'annuler les anciens
 * abonnements Stripe Billing de test ; il n'est plus lu.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['organisations', 'users'] as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom): void {
                $table->date('periode_debut')->nullable()->after($nom === 'organisations' ? 'statut_abonnement' : 'periode_fin');
                $table->unsignedTinyInteger('ancre_jour')->nullable()->after('periode_debut')
                    ->comment('Jour du mois du renouvellement (le dernier jour d’un mois plus court)');
                $table->unsignedTinyInteger('tentatives')->default(0)->after('ancre_jour')
                    ->comment('Prélèvements refusés depuis le dernier paiement');
                $table->timestamp('prochaine_tentative_le')->nullable()->after('tentatives');
            });
        }
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('formule_suivante', 12)->nullable()->after('plan')
                ->comment('Formule moins chère choisie : appliquée au prochain renouvellement');
        });

        // Périodes en cours reprises des anciens abonnements Stripe
        foreach (['organisations', 'users'] as $nom) {
            foreach (DB::table($nom)->whereNotNull('periode_fin')->whereIn('statut_abonnement', ['actif', 'impaye', 'incomplet'])->get() as $ligne) {
                $fin = CarbonImmutable::parse($ligne->periode_fin);
                $annuel = $nom === 'users' || ($ligne->cycle ?? 'mois') === 'an';
                DB::table($nom)->where('id', $ligne->id)->update([
                    'periode_debut' => ($annuel ? $fin->subYearNoOverflow() : $fin->subMonthNoOverflow())->toDateString(),
                    'ancre_jour' => $fin->day,
                    'statut_abonnement' => $ligne->statut_abonnement === 'incomplet' ? 'impaye' : $ligne->statut_abonnement,
                ]);
            }
        }

        foreach (['organisation_factures', 'proprietaire_factures'] as $nom) {
            Schema::table($nom, function (Blueprint $table): void {
                $table->json('lignes')->nullable()->after('libelle');
                $table->date('periode_debut')->nullable()->after('lignes');
                $table->date('periode_fin')->nullable()->after('periode_debut');
                $table->string('stripe_paiement_id')->nullable()->unique()->after('stripe_facture_id');
                $table->timestamp('paye_le')->nullable()->after('statut');
                $table->string('carte')->nullable()->after('paye_le')->comment('Carte prélevée, « Visa •••• 4242 »');
            });
        }
        Schema::table('proprietaire_factures', function (Blueprint $table): void {
            $table->string('stripe_facture_id')->nullable()->change();
        });

        Schema::create('sequence_factures_plateforme', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annee')->primary();
            $table->unsignedInteger('prochain')->default(1);
        });

        // Minutes de transcription facturées par période d'abonnement
        Schema::table('facturations_transcription', function (Blueprint $table): void {
            $table->renameColumn('mois', 'periode_debut');
        });
        Schema::table('facturations_transcription', function (Blueprint $table): void {
            $table->date('periode_fin')->nullable()->after('periode_debut');
            $table->foreignId('organisation_facture_id')->nullable()->after('montant_centimes')->constrained('organisation_factures')->nullOnDelete();
            $table->dropColumn(['stripe_ligne_id', 'stripe_facture_id']);
        });
    }

    public function down(): void
    {
        Schema::table('facturations_transcription', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organisation_facture_id');
            $table->dropColumn('periode_fin');
            $table->string('stripe_ligne_id')->nullable();
            $table->string('stripe_facture_id')->nullable();
        });
        Schema::table('facturations_transcription', function (Blueprint $table): void {
            $table->renameColumn('periode_debut', 'mois');
        });
        Schema::dropIfExists('sequence_factures_plateforme');
        foreach (['organisation_factures', 'proprietaire_factures'] as $nom) {
            Schema::table($nom, function (Blueprint $table): void {
                $table->dropUnique(['stripe_paiement_id']);
                $table->dropColumn(['lignes', 'periode_debut', 'periode_fin', 'stripe_paiement_id', 'paye_le', 'carte']);
            });
        }
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('formule_suivante');
        });
        foreach (['organisations', 'users'] as $nom) {
            Schema::table($nom, function (Blueprint $table): void {
                $table->dropColumn(['periode_debut', 'ancre_jour', 'tentatives', 'prochaine_tentative_le']);
            });
        }
    }
};
