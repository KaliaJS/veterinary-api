<?php

use App\Enums\StatutContrat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * États RH de l'écran Organisation, tranchés sur la table
     * `employes` unique (docs/data-model.md §1 et §2 — les deux
     * représentations d'équipe du front sont deux vues de cette table).
     * Le détail du formulaire de licenciement (soldes, documents de
     * sortie, kit matériel) vit dans le PDF archivé au dossier — seul
     * l'essentiel est stocké.
     */
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->boolean('acces_suspendu')->default(false)->after('conges_pris');
            $table->enum('contrat_statut', array_column(StatutContrat::cases(), 'value'))
                ->default(StatutContrat::Signe->value)->after('acces_suspendu');
            $table->decimal('salaire_mensuel', 8, 2)->nullable()->after('contrat_statut');
            $table->date('licenciement_notifie_le')->nullable()->after('salaire_mensuel');
            $table->date('fin_contrat_le')->nullable()->after('licenciement_notifie_le');
            $table->string('licenciement_motif')->nullable()->after('fin_contrat_le');
        });
    }

    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->dropColumn([
                'acces_suspendu', 'contrat_statut', 'salaire_mensuel',
                'licenciement_notifie_le', 'fin_contrat_le', 'licenciement_motif',
            ]);
        });
    }
};
