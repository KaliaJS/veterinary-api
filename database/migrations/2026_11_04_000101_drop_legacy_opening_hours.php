<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les anciennes colonnes (une ouverture et une fermeture communes, le
     * samedi à part, une pause commune) sont reprises dans `days` par la
     * migration précédente : plus personne ne les lit.
     */
    public function up(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->dropColumn(['ouverture', 'fermeture', 'fermeture_samedi', 'samedi_ferme', 'dimanche_ferme', 'pause_active', 'pause_debut', 'pause_fin']);
        });
    }

    /** Retour en arrière : les colonnes reviennent, remplies d'après le lundi, le samedi et le dimanche. */
    public function down(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->time('ouverture')->default('08:00');
            $table->time('fermeture')->default('19:30');
            $table->time('fermeture_samedi')->nullable();
            $table->boolean('samedi_ferme')->default(false);
            $table->boolean('dimanche_ferme')->default(true);
            $table->boolean('pause_active')->default(true);
            $table->time('pause_debut')->nullable();
            $table->time('pause_fin')->nullable();
        });

        DB::table('horaires_ouverture')->orderBy('id')->each(function (object $ligne): void {
            $jours = json_decode((string) $ligne->days, true) ?: [];
            $lundi = $jours[0]['ranges'] ?? [];
            if ($lundi === []) {
                return;
            }
            $deux = count($lundi) > 1;
            DB::table('horaires_ouverture')->where('id', $ligne->id)->update([
                'ouverture' => $lundi[0]['start'],
                'fermeture' => end($lundi)['end'],
                'fermeture_samedi' => isset($jours[5]['ranges']) && $jours[5]['ranges'] !== [] ? end($jours[5]['ranges'])['end'] : null,
                'samedi_ferme' => ! ($jours[5]['is_open'] ?? false),
                'dimanche_ferme' => ! ($jours[6]['is_open'] ?? false),
                'pause_active' => $deux,
                'pause_debut' => $deux ? $lundi[0]['end'] : null,
                'pause_fin' => $deux ? $lundi[1]['start'] : null,
            ]);
        });
    }
};
