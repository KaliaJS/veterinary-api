<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lettre de sortie d'hospitalisation (choix de Jim, 09.11.2026) : une
 * par séjour, pour le propriétaire. Le brouillon de l'IA garde ses sources
 * numérotées (le séjour, les traitements, les rapports de la journée
 * signés, le rapport de bloc validé, les consignes tapées, la dictée) et
 * la source de chaque phrase ; les rubriques sont le texte relu. Les
 * médicaments, le contrôle et la clinique sont recopiés de l'ordonnance,
 * du planning et de la fiche de la clinique, figés à la signature. Signée,
 * elle est figée. Un enregistrement peut aussi être la dictée de ses
 * consignes, adossée au séjour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discharge_letters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_id')->unique()->constrained('sejours')->cascadeOnDelete();
            $table->string('status', 16)->default('empty')->comment('empty, writing, draft, failed, signed');
            $table->string('error', 32)->nullable()->comment('writings, budget, writer');
            $table->text('home_notes')->nullable()->comment('Chiffré : les consignes pour la maison, tapées en mots-clés');
            $table->text('draft')->nullable()->comment('Chiffré : sources, phrases et leurs sources, doutes');
            $table->text('sections')->nullable()->comment('Chiffré : le texte relu, par rubrique');
            $table->json('edited_sections')->nullable()->comment('Rubriques corrigées par le vétérinaire');
            $table->json('edited_at')->nullable();
            $table->text('copied')->nullable()->comment('Chiffré : médicaments, contrôle et clinique figés à la signature');
            $table->unsignedTinyInteger('writings')->default(0)->comment('Rédactions demandées à l’IA');
            $table->boolean('is_redraft_pending')->default(false);
            $table->timestamp('written_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->string('signed_by_name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            $table->foreignId('sejour_id')->nullable()->after('intervention_id')->comment('Dictée des consignes de la lettre de sortie')->constrained('sejours')->cascadeOnDelete();
            $table->index(['sejour_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('enregistrements_consultation', function (Blueprint $table): void {
            $table->dropIndex(['sejour_id', 'statut']);
            $table->dropConstrainedForeignId('sejour_id');
        });
        Schema::dropIfExists('discharge_letters');
    }
};
