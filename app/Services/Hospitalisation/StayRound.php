<?php

namespace App\Services\Hospitalisation;

use App\Models\Sejour;
use App\Models\SejourConstante;
use App\Models\SejourSoin;
use App\Models\StayCareCancellation;
use App\Models\StayObservation;
use App\Models\StayPainScore;
use App\Models\StaySheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ce que la ronde montre d'un séjour sur des jours donnés (03.11.2026) :
 * les feuilles, les soins et administrations écrits, les prises prévues
 * des jours à venir, les douleurs et les observations ; et le séjour jour
 * par jour, avec qui a soigné et le résumé de chaque journée. Tout ce que
 * l'écran affiche en chiffres et en phrases vient d'ici.
 */
final class StayRound
{
    public function __construct(private StaySheets $sheets) {}

    /**
     * La ronde d'un séjour entre deux feuilles (jours compris).
     *
     * @return array<string, mixed>
     */
    public function range(Sejour $sejour, string $from, string $to): array
    {
        $first = max($from, $this->sheets->admissionDay($sejour));
        $last = $sejour->sorti_le !== null ? min($to, $this->sheets->lastDay($sejour)) : $to;
        if ($first > $last) {
            return ['id' => $sejour->id, 'sheets' => [], 'cares' => [], 'planned' => [], 'pains' => [], 'observations' => []];
        }
        $afterLast = CarbonImmutable::parse($last)->addDay()->toDateString();
        $inRange = fn (string $sheetDay): bool => $sheetDay >= $first && $sheetDay <= $last;

        $cares = SejourSoin::query()->where('sejour_id', $sejour->id)
            ->whereDate('jour', '>=', $first)->whereDate('jour', '<=', $afterLast)->with(['produit', 'treatment', 'cancellations'])
            ->orderBy('jour')->orderBy('prevu_a')->orderBy('id')->get()
            ->map(fn (SejourSoin $soin): array => $this->care($sejour, $soin))
            ->filter(fn (array $c): bool => $inRange($c['sheet_day']))->values();

        $pains = StayPainScore::query()->where('sejour_id', $sejour->id)->whereDate('day', '>=', $first)->whereDate('day', '<=', $afterLast)
            ->orderBy('day')->orderBy('minute')->orderBy('id')->get()
            ->map(fn (StayPainScore $p): array => $this->pain($p, $sejour))
            ->filter(fn (array $p): bool => $inRange($p['sheet_day']))->values();

        $observations = StayObservation::query()->where('sejour_id', $sejour->id)->whereDate('day', '>=', $first)->whereDate('day', '<=', $afterLast)
            ->orderBy('day')->orderBy('minute')->orderBy('id')->get()
            ->map(fn (StayObservation $o): array => $this->observation($o, $sejour))
            ->filter(fn (array $o): bool => $inRange($o['sheet_day']))->values();

        $stored = StaySheet::query()->where('sejour_id', $sejour->id)->whereDate('day', '>=', $first)->whereDate('day', '<=', $last)->get()
            ->keyBy(fn (StaySheet $s): string => $s->day->toDateString());
        $sheets = [];
        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
            $key = $day->toDateString();
            $sheets[] = ['day' => $key, 'state' => $this->sheets->state($sejour, $key), 'report_status' => $stored->get($key)->report_status ?? StaySheet::REPORT_NONE];
        }

        return [
            'id' => $sejour->id,
            'sheets' => $sheets,
            'cares' => $cares->all(),
            'planned' => $this->sheets->planned($sejour, $sejour->treatments()->with('product')->get(), $first, $last),
            'pains' => $pains->all(),
            'observations' => $observations->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function care(Sejour $sejour, SejourSoin $soin): array
    {
        $day = $soin->jour->toDateString();
        $status = $soin->cancelled_at !== null ? 'cancelled' : ($soin->fait ? 'done' : ($soin->skipped_at !== null ? 'skipped' : 'pending'));

        return [
            'id' => $soin->id,
            'day' => $day,
            'minute' => $soin->prevu_a,
            'sheet_day' => StaySheets::sheetDayOf($day, $soin->prevu_a),
            'label' => $soin->acte,
            'dose' => $soin->dose,
            'is_medication' => $soin->treatment_id !== null,
            'treatment_id' => $soin->treatment_id,
            'product' => $soin->produit === null ? null : ['id' => $soin->produit->id, 'name' => $soin->produit->nom],
            'status' => $status,
            'done_time' => $this->sheets->time($sejour, in_array($status, ['done', 'cancelled'], true) ? $soin->fait_le : $soin->skipped_at),
            'by' => in_array($status, ['done', 'cancelled'], true) ? $soin->done_by_name : ($status === 'skipped' ? $soin->skipped_by_name : null),
            // Annulée (donnée par erreur), barrée : par qui, à quelle heure, après coup sur une feuille close
            'cancelled_label' => $soin->cancelled_at === null ? null
                : 'Annulée par '.$soin->cancelled_by_name.' à '.$this->sheets->time($sejour, $soin->cancelled_at).($soin->cancelled_late ? ', après coup' : ''),
            // Notée après la sortie : la facture est déjà émise (un soin infirmier aussi, sixième vérification)
            'unbilled_label' => $status === 'done' && CareCancellation::isUnbilled($sejour, $soin)
                ? ($soin->treatment_id !== null ? 'Facture déjà émise : cette prise n’est pas facturée' : 'Facture déjà émise : ce soin n’est pas facturé') : null,
            // Donnée puis annulée, avant d'être notée de nouveau : l'historique reste
            'previous_label' => $this->previousLabel($sejour, $soin),
            'reason' => $soin->skip_reason,
            // Noté après coup, sur une feuille close (décision de Jim, 03.11.2026)
            'is_late_note' => $soin->late_noted_at !== null,
            'late_label' => $this->sheets->lateLabel($sejour, $soin->late_noted_at, $status === 'skipped' ? $soin->skipped_by_name : $soin->done_by_name),
            'dose_amount' => $soin->dose_amount,
            // L'unité figée sur la prise donnée, sinon celle de l'article (sixième vérification)
            'dose_unit' => $soin->dose_unit ?? $soin->produit?->dose_unit,
            'prescribed_dose' => $soin->treatment?->dose_amount,
        ];
    }

    /** « Avant : 15 ml donnés à 10:04 par Julie Muller, annulés par Dr Rachel Solal à 10:20 » */
    private function previousLabel(Sejour $sejour, SejourSoin $soin): ?string
    {
        $cancellations = $soin->relationLoaded('cancellations') ? $soin->cancellations : $soin->cancellations()->get();
        // La dernière annulation d'une prise encore annulée se lit déjà dans cancelled_label
        if ($soin->cancelled_at !== null) {
            $cancellations = $cancellations->slice(0, -1);
        }
        if ($cancellations->isEmpty()) {
            return null;
        }
        $isMed = $soin->treatment_id !== null;

        // Sans unité figée (annulation reprise d'avant 000069), celle de l'article (septième vérification)
        return 'Avant : '.$cancellations->map(fn (StayCareCancellation $c): string => ($isMed && $c->dose_amount !== null ? self::number($c->dose_amount).' '.($c->dose_unit ?? $soin->produit?->dose_unit).' donnés' : ($isMed ? 'donnée' : 'fait'))
            .' à '.$this->sheets->time($sejour, $c->given_at).' par '.$c->given_by_name
            .', '.($isMed && $c->dose_amount !== null ? 'annulés' : ($isMed ? 'annulée' : 'annulé')).' par '.$c->cancelled_by_name.' à '.$this->sheets->time($sejour, $c->cancelled_at))->implode(' ; ');
    }

    private static function number(float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'));
    }

    /** @return array<string, mixed> */
    public function pain(StayPainScore $p, ?Sejour $sejour = null): array
    {
        $sejour ??= $p->sejour;
        $day = $p->day->toDateString();

        return [
            'id' => $p->id,
            'day' => $day,
            'minute' => $p->minute,
            'sheet_day' => StaySheets::sheetDayOf($day, $p->minute),
            'time' => self::hm($p->minute),
            'scale' => $p->scale->value,
            'scale_label' => $p->scale->label(),
            'score' => $p->score,
            'max' => $p->max,
            'threshold' => $p->threshold,
            'is_above' => $p->score >= $p->threshold,
            'note' => $p->note,
            'by' => $p->by_name,
            'is_late_note' => $p->late_noted_at !== null,
            'late_label' => $this->sheets->lateLabel($sejour, $p->late_noted_at, $p->by_name),
        ];
    }

    /** @return array<string, mixed> */
    public function observation(StayObservation $o, ?Sejour $sejour = null): array
    {
        $sejour ??= $o->sejour;
        $day = $o->day->toDateString();

        return [
            'id' => $o->id,
            'day' => $day,
            'minute' => $o->minute,
            'sheet_day' => StaySheets::sheetDayOf($day, $o->minute),
            'time' => self::hm($o->minute),
            'kind' => $o->kind->value,
            'label' => $o->kind->label(),
            'value' => $o->value,
            'tone' => $o->kind->tone($o->value),
            'by' => $o->by_name,
            'is_late_note' => $o->late_noted_at !== null,
            'late_label' => $this->sheets->lateLabel($sejour, $o->late_noted_at, $o->by_name),
        ];
    }

    /**
     * Le séjour jour par jour, du plus récent au plus ancien, demain en tête
     * tant que l'animal est là.
     *
     * @return array<string, mixed>
     */
    public function days(Sejour $sejour): array
    {
        $first = $this->sheets->admissionDay($sejour);
        $last = $sejour->sorti_le !== null ? $this->sheets->lastDay($sejour) : CarbonImmutable::parse($this->sheets->today($sejour))->addDay()->toDateString();
        $round = $this->range($sejour, $first, $last);
        $cares = collect($round['cares'])->groupBy('sheet_day');
        $planned = collect($round['planned'])->groupBy('sheet_day');
        $pains = collect($round['pains'])->groupBy('sheet_day');
        $observations = collect($round['observations'])->groupBy('sheet_day');
        // Les constantes comptent dans « Soigné par » (03.11.2026)
        $vitals = SejourConstante::query()->where('sejour_id', $sejour->id)->whereNotNull('by_name')->get()
            ->map(fn (SejourConstante $c): array => ['minute' => $c->mesure_a, 'by' => $c->by_name, 'sheet_day' => StaySheets::sheetDayOf($c->jour->toDateString(), $c->mesure_a)])
            ->groupBy('sheet_day');

        $days = collect($round['sheets'])->map(function (array $sheet) use ($sejour, $cares, $planned, $pains, $observations, $vitals): array {
            $day = $sheet['day'];
            $dayCares = $cares->get($day, collect());
            $dayPains = $pains->get($day, collect());
            $dayObs = $observations->get($day, collect())->concat($vitals->get($day, collect()));

            return [
                'day' => $day,
                'number' => $this->sheets->dayNumber($sejour, $day),
                'state' => $sheet['state'],
                'report_status' => $sheet['report_status'],
                'carers' => $sheet['state'] === 'planned' ? [] : $this->carers($dayCares, $dayPains, $dayObs),
                'summary' => $sheet['state'] === 'planned'
                    ? $this->plannedSummary($dayCares, $planned->get($day, collect()))
                    : $this->summary($dayCares, $dayPains, $dayObs),
            ];
        })->reverse()->values()->all();

        return [
            'days' => $days,
            'admission' => [
                'day' => $this->sheets->local($sejour, $sejour->admis_le)['day'],
                'time' => $this->sheets->time($sejour, $sejour->admis_le),
                // Qui a admis (figé à l'admission) ; le vétérinaire référent à part
                'by' => $sejour->admitted_by_name,
                'referent' => $sejour->veterinaire?->nom,
            ],
        ];
    }

    /**
     * Ceux qui ont soigné l'animal ce jour-là (vétérinaires et assistantes),
     * dans l'ordre de la journée.
     *
     * @param  Collection<int, array<string, mixed>>  $cares
     * @param  Collection<int, array<string, mixed>>  $pains
     * @param  Collection<int, array<string, mixed>>  $observations
     * @return list<string>
     */
    public function carers(Collection $cares, Collection $pains, Collection $observations): array
    {
        $order = fn (array $x): int => $x['minute'] < StaySheets::START ? $x['minute'] + 1440 : $x['minute'];

        return collect()
            ->concat($cares->where('status', '!=', 'pending')->map(fn (array $c): array => ['o' => $order($c), 'by' => $c['by']]))
            ->concat($pains->map(fn (array $p): array => ['o' => $order($p), 'by' => $p['by']]))
            ->concat($observations->map(fn (array $o): array => ['o' => $order($o), 'by' => $o['by']]))
            ->sortBy('o')->pluck('by')->filter()->unique()->values()->all();
    }

    /**
     * « Douleur 7 puis 4 sur 24 · 2 médicaments sur 2 · mange : moitié »
     *
     * @param  Collection<int, array<string, mixed>>  $cares
     * @param  Collection<int, array<string, mixed>>  $pains
     * @param  Collection<int, array<string, mixed>>  $observations
     */
    public function summary(Collection $cares, Collection $pains, Collection $observations): string
    {
        $parts = [];
        $parts[] = match ($pains->count()) {
            0 => 'Douleur non évaluée',
            1 => 'Douleur '.$pains->first()['score'].' sur '.$pains->first()['max'],
            default => 'Douleur '.$pains->first()['score'].' puis '.$pains->last()['score'].' sur '.$pains->last()['max'],
        };
        // Une prise annulée (donnée par erreur) ne se compte pas (sixième vérification)
        $meds = $cares->where('is_medication', true)->where('status', '!=', 'cancelled');
        if ($meds->isNotEmpty()) {
            $given = $meds->where('status', 'done')->count();
            $parts[] = $given.' médicament'.($given > 1 ? 's' : '').' sur '.$meds->count();
        }
        $meal = $observations->where('kind', 'eats')->sortBy(fn (array $o): int => $o['minute'] < StaySheets::START ? $o['minute'] + 1440 : $o['minute'])->last();
        $parts[] = $meal !== null ? 'mange : '.$meal['value'] : 'repas non noté';

        return implode(' · ', $parts);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cares
     * @param  Collection<int, array<string, mixed>>  $planned
     */
    public function plannedSummary(Collection $cares, Collection $planned): string
    {
        $meds = $planned->count() + $cares->where('is_medication', true)->count();
        $soins = $cares->where('is_medication', false)->count();
        if ($meds === 0 && $soins === 0) {
            return 'Rien de prévu';
        }
        $medsText = $meds.' médicament'.($meds > 1 ? 's' : '');
        $soinsText = $soins.' soin'.($soins > 1 ? 's' : '');

        return match (true) {
            $soins === 0 => $medsText.' prévu'.($meds > 1 ? 's' : ''),
            $meds === 0 => $soinsText.' prévu'.($soins > 1 ? 's' : ''),
            default => $medsText.' et '.$soinsText.' prévus',
        };
    }

    public static function hm(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
