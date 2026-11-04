<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Carbon\CarbonInterface;
use Database\Factories\HoraireOuvertureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Horaires jour par jour (demande de Jim, 04.11.2026) : `days` tient les
 * sept jours, du lundi au dimanche, chacun ouvert ou fermé, avec une ou
 * deux plages (matin et après-midi). Un jour fermé garde ses plages, pour
 * les retrouver quand il rouvre. Entre deux plages, la clinique est
 * fermée : aucun créneau n'y tombe.
 *
 * Les heures sont des heures LOCALES (fuseau de la clinique,
 * `cliniques.fuseau_horaire`), pas des instants : « ouvre à 08:00 »
 * vaut été comme hiver. Seuls les rendez-vous sont des datetimes UTC.
 */
class HoraireOuverture extends Model
{
    /** @use HasFactory<HoraireOuvertureFactory> */
    use AppartientAUneClinique, HasFactory;

    /**
     * Les horaires d'une clinique neuve : du lundi au vendredi de 08:00 à
     * 19:30 coupés de 12:30 à 14:00, le samedi jusqu'à 15:00, fermé le
     * dimanche (les valeurs de départ d'avant, jour par jour).
     */
    public const array DEFAUTS = [
        'days' => [
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '15:00']]],
            ['is_open' => false, 'ranges' => [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']]],
        ],
    ];

    protected $table = 'horaires_ouverture';

    protected $fillable = ['clinique_id', 'days', 'revision_writer', 'revision_seq'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'revision_seq' => 'integer',
        ];
    }

    /**
     * Les plages d'un jour, en minutes locales depuis minuit, dans l'ordre ;
     * vide si la clinique est fermée ce jour-là.
     *
     * @return list<array{int, int}>
     */
    public function rangesOn(CarbonInterface $day): array
    {
        $jour = ($this->days ?? self::DEFAUTS['days'])[$day->dayOfWeekIso - 1] ?? null;
        if (! ($jour['is_open'] ?? false)) {
            return [];
        }

        $minutes = fn (string $heure): int => (int) substr($heure, 0, 2) * 60 + (int) substr($heure, 3, 2);
        $plages = array_values(array_filter(
            array_map(fn (array $plage): array => [$minutes($plage['start']), $minutes($plage['end'])], $jour['ranges'] ?? []),
            fn (array $plage): bool => $plage[1] > $plage[0],
        ));
        usort($plages, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $plages;
    }

    /**
     * Une semaine écrite comme avant (une ouverture et une fermeture
     * communes, le samedi à part, une pause commune), jour par jour : les
     * essais et les données de démonstration s'écrivent ainsi en une ligne.
     *
     * @param  array{ouverture?: string, fermeture?: string, fermeture_samedi?: string|null, samedi_ferme?: bool, dimanche_ferme?: bool, pause_active?: bool, pause_debut?: string, pause_fin?: string}  $semaine
     * @return list<array{is_open: bool, ranges: list<array{start: string, end: string}>}>
     */
    public static function weekLike(array $semaine): array
    {
        $s = [
            'ouverture' => '08:00', 'fermeture' => '19:30', 'fermeture_samedi' => '15:00', 'samedi_ferme' => false,
            'dimanche_ferme' => true, 'pause_active' => true, 'pause_debut' => '12:30', 'pause_fin' => '14:00', ...$semaine,
        ];
        $jour = fn (bool $ferme, string $fermeture): array => [
            'is_open' => ! $ferme,
            'ranges' => $s['pause_active'] && $fermeture > $s['pause_fin']
                ? [['start' => $s['ouverture'], 'end' => $s['pause_debut']], ['start' => $s['pause_fin'], 'end' => $fermeture]]
                : [['start' => $s['ouverture'], 'end' => $fermeture]],
        ];
        $semaine = $jour(false, $s['fermeture']);

        return [$semaine, $semaine, $semaine, $semaine, $semaine, $jour($s['samedi_ferme'], $s['fermeture_samedi'] ?? $s['fermeture']), $jour($s['dimanche_ferme'], $s['fermeture'])];
    }

    /**
     * Enregistre les sept jours, sous verrou, et dit s'ils ont été écrits
     * (sixième passe, 04.11.2026). `$writer` : l'onglet qui envoie (un uuid
     * tiré à son ouverture), `$seq` : son compteur. Seul un envoi plus
     * ancien du même onglet (même `writer`, `seq` inférieur ou égal) est
     * ignoré : l'état en base est rendu tel quel. Entre onglets ou
     * personnes, la dernière écriture gagne. Sans onglet (ancien client),
     * l'envoi s'écrit et remet l'ordre à zéro.
     *
     * @param  list<array{is_open: bool, ranges: list<array{start: string, end: string}>}>  $days
     * @return array{0: self, 1: bool} la fiche, et si elle a été écrite
     */
    public function writeDays(array $days, ?string $writer, ?int $seq): array
    {
        return DB::transaction(function () use ($days, $writer, $seq): array {
            $fiche = self::withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($writer !== null && $fiche->revision_writer === $writer && $fiche->revision_seq !== null && $seq <= $fiche->revision_seq) {
                return [$fiche, false];
            }
            $fiche->update(['days' => $days, 'revision_writer' => $writer, 'revision_seq' => $writer !== null ? $seq : null]);

            return [$fiche, true];
        });
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
