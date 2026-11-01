<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La date d'une ligne du registre des stupéfiants (art. 57, al. 3 OCStup,
 * vérification du 01.11.2026) : celle du bulletin de livraison d'une
 * réception, sinon le jour de l'inscription à l'heure de la clinique. Le
 * bilan d'une période se fait sur elle. Les lignes existantes la reçoivent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->date('date_registre')->nullable()->after('created_at');
            $table->index(['clinique_id', 'produit_id', 'date_registre'], 'mouvements_stupefiants_date_registre_index');
        });

        $fuseaux = DB::table('cliniques')->pluck('fuseau_horaire', 'id');
        DB::table('mouvements_stupefiants')->orderBy('id')->select(['id', 'clinique_id', 'type', 'bulletin_le', 'created_at'])
            ->each(function (object $ligne) use ($fuseaux): void {
                $date = $ligne->type === 'reception' && $ligne->bulletin_le !== null
                    ? substr((string) $ligne->bulletin_le, 0, 10)
                    : CarbonImmutable::parse($ligne->created_at, 'UTC')->setTimezone($fuseaux[$ligne->clinique_id] ?: 'Europe/Zurich')->toDateString();
                DB::table('mouvements_stupefiants')->where('id', $ligne->id)->update(['date_registre' => $date]);
            });
    }

    public function down(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table): void {
            $table->dropIndex('mouvements_stupefiants_date_registre_index');
            $table->dropColumn('date_registre');
        });
    }
};
