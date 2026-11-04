<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma dentaire modernisé (demande de Jim, 04.11.2026) :
 * - une dent porte une liste d'états (`etats`) et une note facultative ;
 *   chaque ancien relevé (un seul `etat`) devient une liste d'un état, sans
 *   perte, puis l'ancienne colonne s'efface ;
 * - le bilan de la bouche du patient (gingivite, tartre, occlusion, bloc
 *   local), une ligne par patient ;
 * - la vue du schéma choisie par la personne, gardée avec ses autres
 *   préférences.
 * Les listes d'états sont écrites ici, pas lues dans les enums, qui
 * changeront encore.
 */
return new class extends Migration
{
    /** Les états que connaissait l'ancienne colonne `etat` */
    private const OLD_STATES = ['tartre', 'gingivite', 'parodontite', 'fracture', 'tumeur', 'extraction', 'absente'];

    /** Au retour, une seule place : l'état le plus grave d'abord */
    private const BY_SEVERITY = ['tumeur', 'fracture', 'parodontite', 'extraction', 'absente', 'gingivite', 'tartre'];

    public function up(): void
    {
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->json('etats')->nullable()->comment('États de la dent (tartre, gingivite, mobilite…), plusieurs à la fois');
            $table->string('note', 500)->nullable()->comment('Note libre sur la dent');
        });

        // Par identifiant, pas par page : la mise à jour ne décale rien
        DB::table('releves_dentaires')->select(['id', 'etat'])->lazyById()->each(function (object $releve): void {
            DB::table('releves_dentaires')->where('id', $releve->id)->update(['etats' => json_encode([$releve->etat])]);
        });

        // Chaque ligne a sa liste : elle n'est plus jamais nulle
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->dropColumn('etat');
            $table->json('etats')->nullable(false)->comment('États de la dent (tartre, gingivite, mobilite…), plusieurs à la fois')->change();
        });

        Schema::create('dental_overviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gingivitis', 16)->nullable()->comment('none, mild, moderate, severe');
            $table->string('calculus', 16)->nullable()->comment('none, minimal, mild, moderate, heavy');
            $table->string('occlusion', 16)->nullable()->comment('normal, MAL1 à MAL4 (AVDC)');
            $table->string('local_block', 200)->nullable()->comment('Bloc local (produit, site)');
            $table->foreignId('source_organisation_id')->nullable()->comment('Organisation qui a fait l’acte (aucune : le propriétaire)')->constrained('organisations')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('staff_preferences', function (Blueprint $table): void {
            $table->string('dental_chart_view', 1)->nullable()->comment('Vue du schéma dentaire : A fiche AVDC, B carte en couleur, C vue de côté, D quadrants');
        });
    }

    public function down(): void
    {
        Schema::table('staff_preferences', function (Blueprint $table): void {
            $table->dropColumn('dental_chart_view');
        });

        Schema::dropIfExists('dental_overviews');

        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->enum('etat', self::OLD_STATES)->nullable();
        });

        // Une seule place : l'état le plus grave que l'ancienne liste connaît ;
        // une dent sans aucun (note seule, mobilité, résorption) redevient
        // saine. Les effacements attendent la fin du parcours.
        $sansEtat = [];
        DB::table('releves_dentaires')->select(['id', 'etats'])->lazyById()->each(function (object $releve) use (&$sansEtat): void {
            $etats = json_decode((string) $releve->etats, true) ?: [];
            $etat = collect(self::BY_SEVERITY)->first(fn (string $e) => in_array($e, $etats, true));
            if ($etat === null) {
                $sansEtat[] = $releve->id;
            } else {
                DB::table('releves_dentaires')->where('id', $releve->id)->update(['etat' => $etat]);
            }
        });
        foreach (array_chunk($sansEtat, 500) as $ids) {
            DB::table('releves_dentaires')->whereIn('id', $ids)->delete();
        }

        // NOT NULL, comme à l'origine
        Schema::table('releves_dentaires', function (Blueprint $table): void {
            $table->dropColumn(['etats', 'note']);
            $table->enum('etat', self::OLD_STATES)->nullable(false)->change();
        });
    }
};
