<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horaires jour par jour (demande de Jim, 04.11.2026) : sept jours, du
     * lundi au dimanche, chacun ouvert ou fermé, avec une ou deux plages
     * (matin et après-midi). Les horaires de chaque clinique sont repris :
     * chaque jour reçoit l'ouverture et la fermeture actuelles, coupées par
     * la pause si elle est active ; le samedi (sa fermeture propre) et le
     * dimanche suivent leurs réglages. La pause ne coupait la journée que
     * si la fermeture la dépassait : un samedi qui ferme avant la fin de la
     * pause garde une seule plage, comme le planning l'affichait.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('horaires_ouverture', 'days')) {
            Schema::table('horaires_ouverture', function (Blueprint $table) {
                $table->json('days')->nullable()->after('clinique_id');
            });
        }

        DB::table('horaires_ouverture')->orderBy('id')->each(function (object $ligne): void {
            DB::table('horaires_ouverture')->where('id', $ligne->id)->update(['days' => json_encode($this->days($ligne))]);
        });
    }

    /**
     * Les sept jours d'une ligne au format d'avant. Robuste aux données
     * d'avant la validation (04.11.2026) : une pause inversée, hors des
     * heures ou de moins de 15 minutes est ignorée ; une plage de moins de
     * 30 minutes n'est pas gardée ; un jour sans plage valide est fermé et
     * sans plage, pour se rouvrir sans refus.
     *
     * @return list<array{is_open: bool, ranges: list<array{start: string, end: string}>}>
     */
    public function days(object $ligne): array
    {
        $heure = fn (?string $valeur): ?string => $valeur === null ? null : substr($valeur, 0, 5);
        $minutes = fn (string $valeur): int => (int) substr($valeur, 0, 2) * 60 + (int) substr($valeur, 3, 2);

        $ouverture = $heure($ligne->ouverture);
        $pause = (bool) $ligne->pause_active && $ligne->pause_debut !== null && $ligne->pause_fin !== null
            ? [$heure($ligne->pause_debut), $heure($ligne->pause_fin)]
            : null;

        $jour = function (bool $ferme, string $fermeture) use ($ouverture, $pause, $minutes): array {
            // La pause ne coupe la journée que si elle tombe dedans, dans le bon sens
            $coupe = $pause !== null
                && $minutes($pause[0]) > $minutes($ouverture)
                && $minutes($pause[1]) >= $minutes($pause[0]) + 15
                && $minutes($fermeture) > $minutes($pause[1]);
            $plages = $coupe
                ? [['start' => $ouverture, 'end' => $pause[0]], ['start' => $pause[1], 'end' => $fermeture]]
                : [['start' => $ouverture, 'end' => $fermeture]];
            $plages = array_values(array_filter($plages, fn (array $p): bool => $minutes($p['end']) >= $minutes($p['start']) + 30));

            return ['is_open' => ! $ferme && $plages !== [], 'ranges' => $plages];
        };

        $semaine = $jour(false, $heure($ligne->fermeture));

        return [
            $semaine,
            $semaine,
            $semaine,
            $semaine,
            $semaine,
            $jour((bool) $ligne->samedi_ferme, $heure($ligne->fermeture_samedi) ?? $heure($ligne->fermeture)),
            $jour((bool) $ligne->dimanche_ferme, $heure($ligne->fermeture)),
        ];
    }

    public function down(): void
    {
        Schema::table('horaires_ouverture', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
