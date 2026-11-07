<?php

namespace App\Services\Facturation;

use App\Enums\Espece;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\LignePlanning;
use App\Services\Planning\PlanningOccupancy;
use App\Support\WallClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Les statistiques de la Caisse sur une période (demande de Jim,
 * 06.11.2026) : tout est dérivé des factures, des rendez-vous et des
 * horaires, rien n'est stocké ni recalculé par l'écran.
 *
 * - L'encaissé est le total TTC des factures payées dans la période
 *   (`payee_le`), acompte en ligne compris ; les moyens de paiement le
 *   répartissent : ce qui est passé à la caisse par moyen, et les acomptes
 *   payés en ligne à part.
 * - Les actes sont les lignes d'acte des factures émises dans la période,
 *   hors annulées, remises et parts d'acompte.
 * - Les factures en attente sont celles dues à ce jour, quelle que soit la
 *   période, avec leur âge en jours depuis l'émission.
 * - L'occupation du planning : PlanningOccupancy.
 *
 * Les jours sont ceux du calendrier de la clinique (son fuseau). Le nombre
 * de requêtes ne dépend ni de la longueur de la période ni du nombre de
 * factures.
 *
 * @phpstan-type PaidInvoice array{clinic: string, lane: string, species: string, method: string, total: float, deposit: float, cashed: float, day: string}
 * @phpstan-type DueInvoice array{issued_at: string, id: int, number: string, owner_name: string|null, patient_name: string|null, clinic_id: int, lane: int|null, issued_on: string, age_days: int, amount: float}
 * @phpstan-type Totals array{revenue: float, count: int, clinic: array<string, array{revenue: float, count: int}>, lane: array<string, array{revenue: float, count: int}>, species: array<string, array{revenue: float, count: int}>, method: array<string, array{revenue: float, count: int}>, payments: array<string, float>}
 * @phpstan-type SlotTotals array{revenue: float, count: int, payments: array<string, float>}
 */
class CashStatistics
{
    /** Jusqu'à 45 jours, un créneau par jour ; jusqu'à 210, par semaine ; au-delà, par mois */
    private const int DAY_SLOTS_UP_TO = 45;

    private const int WEEK_SLOTS_UP_TO = 210;

    /** L'aperçu de toute l'histoire : par jour jusqu'à 400 jours, puis par semaine */
    private const int HISTORY_DAYS_UP_TO = 400;

    /** Les moyens de la répartition de l'encaissé, l'acompte en ligne à la fin */
    private const array PAYMENT_KEYS = ['tpe', 'twint', 'cash', 'virement', 'deposit'];

    private const int TOP_ACTS = 10;

    private const int DUE_LISTED = 50;

    private string $timezone;

    /** @var list<int> */
    private array $clinicIds;

    /** @var list<int> */
    private array $organisationClinicIds;

    /** @var array<int, array{name: string, initials: string|null, clinic_id: int}> */
    private array $laneNames = [];

    /**
     * @param  EloquentCollection<int, Clinique>  $clinics  les cliniques comptées
     * @param  EloquentCollection<int, Clinique>  $organisationClinics  toutes celles de l'organisation (le menu)
     * @return array<string, mixed>
     */
    public function compute(EloquentCollection $clinics, EloquentCollection $organisationClinics, ?int $clinicId, string $timezone, CarbonImmutable $from, CarbonImmutable $to, ?string $compare, bool $withHistory): array
    {
        $this->timezone = $timezone;
        $this->clinicIds = $clinics->modelKeys();
        $this->organisationClinicIds = $organisationClinics->modelKeys();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $days = (int) $from->diffInDays($to) + 1;
        $granularity = $days <= self::DAY_SLOTS_UP_TO ? 'day' : ($days <= self::WEEK_SLOTS_UP_TO ? 'week' : 'month');
        $comparison = $this->comparisonOf($from, $to, $compare);

        $slots = $this->slotsOf($from, $to, $granularity);
        $comparisonSlots = $comparison ? $this->comparisonSlotsOf($slots, $comparison[0], $comparison[1], $granularity) : [];

        // Lues par paquets de mille et additionnées au fil de l'eau : la mémoire ne dépend pas du nombre de factures
        $paid = $this->scanPaid($from, $to, $slots);
        $paidBefore = $comparison ? $this->scanPaid($comparison[0], $comparison[1], $comparisonSlots) : null;
        $acts = $this->acts($from, $to);
        $actsBefore = $comparison ? $this->acts($comparison[0], $comparison[1]) : null;
        $due = $this->scanDue($today);

        $occupancy = (new PlanningOccupancy)->load($clinics);
        $occupied = $occupancy->measure($from->toDateString(), $to->toDateString());
        $occupiedBefore = $comparison ? $occupancy->measure($comparison[0]->toDateString(), $comparison[1]->toDateString()) : null;

        $this->loadLaneNames(array_keys($paid['lanes'] + ($paidBefore['lanes'] ?? []) + $due['lanes']), $occupancy->lanes());

        $current = $paid['total'];
        $before = $paidBefore['total'] ?? null;
        $data = [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'granularity' => $granularity,
                'timezone' => $timezone,
            ],
            'comparison' => $comparison ? ['kind' => $compare, 'from' => $comparison[0]->toDateString(), 'to' => $comparison[1]->toDateString()] : null,
            'clinic_id' => $clinicId,
            'clinics' => $organisationClinics->map(fn (Clinique $clinique): array => ['id' => $clinique->id, 'name' => $clinique->nom, 'timezone' => $clinique->fuseau_horaire ?: 'Europe/Zurich'])->values()->all(),
            'indicators' => [
                'revenue' => $this->figure($current['revenue'], $before['revenue'] ?? null),
                'paid_count' => $this->figure($current['count'], $before['count'] ?? null),
                'average_basket' => $this->figure($this->basket($current['revenue'], $current['count']), $before ? $this->basket($before['revenue'], $before['count']) : null),
                'occupancy' => [
                    'percent' => $this->percent($occupied['booked'], $occupied['open']),
                    'booked_minutes' => $occupied['booked'],
                    'open_minutes' => $occupied['open'],
                    'previous_percent' => $occupiedBefore ? $this->percent($occupiedBefore['booked'], $occupiedBefore['open']) : null,
                    'change_pts' => $occupiedBefore ? $this->points($this->percent($occupied['booked'], $occupied['open']), $this->percent($occupiedBefore['booked'], $occupiedBefore['open'])) : null,
                ],
                'acts' => (fn (array $figure): array => ['count' => $figure['value'], 'previous' => $figure['previous'], 'change_pct' => $figure['change_pct']])($this->figure($acts->sum('count'), $actsBefore?->sum('count'))),
                'due' => ['total' => $due['total'], 'count' => $due['count']],
            ],
            'payment_methods' => $this->paymentMethods($current, $before),
            'species' => $this->species($current, $before),
            'acts' => $this->topActs($acts, $actsBefore),
            'due' => $this->due($due),
            'occupancy' => $this->occupancy($occupancy->lanes(), $occupied, $occupiedBefore),
            'breakdowns' => [
                'clinic' => $this->breakdown($current['clinic'], $before['clinic'] ?? null, fn (string $key): string => (string) $organisationClinics->firstWhere('id', (int) $key)?->nom),
                'practitioner' => $this->breakdown($current['lane'], $before['lane'] ?? null, fn (string $key): string => $key === 'none' ? 'Sans praticien' : ($this->laneNames[(int) $key]['name'] ?? ''), fn (string $key): array => $key === 'none' ? [] : ['initials' => $this->laneNames[(int) $key]['initials'] ?? null, 'clinic_id' => $this->laneNames[(int) $key]['clinic_id'] ?? null]),
                'payment' => $this->breakdown($current['method'], $before['method'] ?? null, fn (string $key): string => $key),
                'species' => $this->breakdown($current['species'], $before['species'] ?? null, fn (string $key): string => $this->speciesLabel($key)),
            ],
            'series' => [
                'slots' => $this->series($slots, $paid['slots'], true),
                'comparison' => $paidBefore ? $this->series($comparisonSlots, $paidBefore['slots'], false) : null,
            ],
        ];

        if ($withHistory) {
            $data['history'] = $this->history($today);
        }

        return $data;
    }

    /**
     * La période de comparaison (règles revues le 06.11.2026) : la même
     * l'an dernier, ou la précédente :
     * - un jour seul : la veille ;
     * - des mois entiers : les mois d'avant ;
     * - depuis un 1er du mois, dans le mois (janvier compris) : les mêmes jours du mois d'avant ;
     * - depuis un 1er janvier, sur plus d'un mois de l'année : les mêmes jours de l'année d'avant ;
     * - sinon : autant de jours juste avant.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function comparisonOf(CarbonImmutable $from, CarbonImmutable $to, ?string $compare): ?array
    {
        if ($compare === 'last_year') {
            return [$from->subYearNoOverflow(), $to->subYearNoOverflow()];
        }
        if ($compare !== 'previous') {
            return null;
        }
        if ($from->isSameDay($to)) {
            return [$from->subDay(), $from->subDay()];
        }
        if ($from->day === 1 && $to->isSameDay($to->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

            return [$from->subMonthsNoOverflow($months), $from->subDay()];
        }
        // Dans un seul mois, même en janvier (« Ce mois » : décembre, 07.11.2026)
        if ($from->day === 1 && $to->year === $from->year && $to->month === $from->month) {
            return [$from->subMonthNoOverflow(), $to->subMonthNoOverflow()];
        }
        if ($from->day === 1 && $from->month === 1 && $to->year === $from->year) {
            return [$from->subYearNoOverflow(), $to->subYearNoOverflow()];
        }
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }

    /** Les lignes d'un paquet de factures : une requête par paquet */
    private const int CHUNK = 1000;

    /**
     * Les factures d'une requête, lues par paquets de mille dans l'ordre de
     * leur numéro d'ordre (07.11.2026) : sous MySQL, une requête lue « ligne à
     * ligne » garde quand même tout son résultat en mémoire.
     *
     * @param  Builder<Facture>  $query
     * @return iterable<int, \stdClass>
     */
    private function inChunks(Builder $query): iterable
    {
        return $query->toBase()->lazyById(self::CHUNK, 'factures.id', 'id');
    }

    /** L'instant UTC où commence ce jour du calendrier de la clinique */
    private function startOf(CarbonImmutable $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString(), $this->timezone)->utc();
    }

    private ?WallClock $clock = null;

    /**
     * Le jour du calendrier de la clinique d'un horodatage de la base (UTC),
     * lu pour chaque facture sans objet date (WallClock, vérification du
     * 06.11.2026 : dix ans en flux).
     */
    private function localDay(?string $utc): ?string
    {
        if ($utc === null) {
            return null;
        }
        $this->clock ??= new WallClock($this->timezone);

        return $this->clock->day($utc);
    }

    /**
     * Le total TTC de chaque facture, en une sous-requête : jamais une
     * requête par facture.
     *
     * @param  list<string>  $columns
     * @return Builder<Facture>
     */
    private function invoicesWithTotal(array $columns): Builder
    {
        $lines = (new FactureLigne)->getTable();

        return Facture::query()
            ->select($columns)
            ->whereIn('clinique_id', $this->clinicIds)
            ->addSelect(['lines_total' => FactureLigne::query()
                ->selectRaw('coalesce(sum(quantite * prix_chf), 0)')
                ->whereColumn($lines.'.facture_id', 'factures.id')]);
    }

    /**
     * Les factures payées dans la période, lues par paquets de mille (une
     * requête par paquet, jamais tout le résultat d'un coup : MySQL garde en
     * mémoire le résultat entier d'une requête) et additionnées au fil de l'eau : les totaux et leurs
     * ventilations, chaque créneau, et les colonnes rencontrées.
     *
     * @param  list<array{from: string, to: string}>  $slots
     * @return array{total: Totals, slots: list<SlotTotals>, lanes: array<int, true>}
     */
    private function scanPaid(CarbonImmutable $from, CarbonImmutable $to, array $slots): array
    {
        $total = $this->emptyTotals();
        $perSlot = array_map(fn (): array => ['revenue' => 0.0, 'count' => 0, 'payments' => array_fill_keys(self::PAYMENT_KEYS, 0.0)], $slots);
        $lanes = [];

        $rows = $this->inChunks($this->invoicesWithTotal(['factures.id', 'factures.clinique_id', 'factures.ligne_planning_id', 'factures.species', 'factures.moyen_paiement', 'factures.acompte', 'factures.payee_le'])
            ->where('statut', StatutFacture::Payee)
            ->where('payee_le', '>=', $this->startOf($from))
            ->where('payee_le', '<', $this->startOf($to->addDay())));

        foreach ($rows as $row) {
            $invoice = $this->paidInvoice($row);
            $this->addTo($total, $invoice);
            if ($invoice['lane'] !== 'none') {
                $lanes[(int) $invoice['lane']] = true;
            }
            // Une comparaison plus longue que la période : ses jours en trop restent hors des créneaux
            $index = $slots === [] ? null : $this->slotIndex($slots, $invoice['day']);
            if ($index !== null && $invoice['day'] >= $slots[$index]['from'] && $invoice['day'] <= $slots[$index]['to']) {
                $perSlot[$index]['revenue'] += $invoice['total'];
                $perSlot[$index]['count']++;
                $this->addPayments($perSlot[$index]['payments'], $invoice);
            }
        }
        $total['revenue'] = round($total['revenue'], 2);

        return ['total' => $total, 'slots' => $perSlot, 'lanes' => $lanes];
    }

    /** @return PaidInvoice */
    private function paidInvoice(object $row): array
    {
        $total = round((float) $row->lines_total, 2);
        // Un acompte plus grand que la facture n'en paie que le total (vérification du 06.11.2026)
        $deposit = min(max(0.0, round((float) $row->acompte, 2)), max(0.0, $total));

        return [
            'clinic' => (string) $row->clinique_id,
            'lane' => $row->ligne_planning_id === null ? 'none' : (string) $row->ligne_planning_id,
            'species' => $row->species === null ? 'none' : (string) $row->species,
            'method' => $row->moyen_paiement === null ? 'none' : (string) $row->moyen_paiement,
            'total' => $total,
            'deposit' => $deposit,
            // Ce qui est passé à la caisse : le total moins la part payée par l'acompte en ligne
            'cashed' => round($total - $deposit, 2),
            'day' => (string) $this->localDay((string) $row->payee_le),
        ];
    }

    /**
     * Les actes des factures émises dans la période, par libellé.
     *
     * @return Collection<string, array{label: string, count: int, revenue: float}>
     */
    private function acts(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $invoices = Facture::query()
            ->select('factures.id')
            ->whereIn('clinique_id', $this->clinicIds)
            ->where('statut', '!=', StatutFacture::Annulee)
            ->where('emise_le', '>=', $this->startOf($from))
            ->where('emise_le', '<', $this->startOf($to->addDay()));

        return FactureLigne::query()
            ->where('type', TypeLigneFacture::Acte)
            ->where('prix_chf', '>=', 0)
            ->where(fn (Builder $lines) => $lines->whereNull('vat_context')->orWhereNotIn('vat_context', [VatContext::Discount->value, VatContext::Deposit->value]))
            ->whereIn('facture_id', $invoices)
            ->groupBy('libelle')
            ->selectRaw('libelle, sum(quantite) as acts_count, sum(quantite * prix_chf) as acts_revenue')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->libelle => [
                'label' => (string) $row->libelle,
                'count' => (int) $row->acts_count,
                'revenue' => round((float) $row->acts_revenue, 2),
            ]]);
    }

    /**
     * Les factures dues à ce jour, lues par paquets de mille : le total et le
     * nombre, l'âge par tranche, et les plus anciennes à lister (gardées au
     * fil de la lecture, jamais toutes en mémoire).
     *
     * @return array{total: float, count: int, aging: array<string, array{total: float, count: int}>, listed: list<DueInvoice>, lanes: array<int, true>}
     */
    private function scanDue(CarbonImmutable $today): array
    {
        $out = ['total' => 0.0, 'count' => 0, 'aging' => array_fill_keys(['under_30', 'from_30_to_59', 'over_60'], ['total' => 0.0, 'count' => 0]), 'listed' => [], 'lanes' => []];
        $todayN = CarbonImmutable::parse($today->toDateString());

        $rows = $this->inChunks($this->invoicesWithTotal(['factures.id', 'factures.numero', 'factures.owner_name', 'factures.patient_name', 'factures.clinique_id', 'factures.ligne_planning_id', 'factures.acompte', 'factures.emise_le'])
            ->where('statut', StatutFacture::Due));
        // Les plus anciennes (émission, puis numéro d'ordre), triées de temps en temps pour rester peu nombreuses
        $oldest = fn (array $a, array $b): int => [$a['issued_at'], $a['id']] <=> [$b['issued_at'], $b['id']];

        foreach ($rows as $row) {
            $issuedOn = (string) $this->localDay((string) $row->emise_le);
            // Ce qui reste à payer : l'acompte en ligne est déjà là
            $amount = max(0.0, round((float) $row->lines_total - (float) $row->acompte, 2));
            $age = (int) CarbonImmutable::parse($issuedOn)->diffInDays($todayN);
            $bucket = $age < 30 ? 'under_30' : ($age < 60 ? 'from_30_to_59' : 'over_60');
            $out['total'] += $amount;
            $out['count']++;
            $out['aging'][$bucket]['total'] += $amount;
            $out['aging'][$bucket]['count']++;
            $out['listed'][] = [
                'issued_at' => (string) $row->emise_le,
                'id' => (int) $row->id,
                'number' => (string) $row->numero,
                'owner_name' => $row->owner_name === null ? null : (string) $row->owner_name,
                'patient_name' => $row->patient_name === null ? null : (string) $row->patient_name,
                'clinic_id' => (int) $row->clinique_id,
                'lane' => $row->ligne_planning_id === null ? null : (int) $row->ligne_planning_id,
                'issued_on' => $issuedOn,
                'age_days' => $age,
                'amount' => $amount,
            ];
            if (count($out['listed']) >= 2 * self::DUE_LISTED) {
                usort($out['listed'], $oldest);
                $out['listed'] = array_slice($out['listed'], 0, self::DUE_LISTED);
            }
        }
        usort($out['listed'], $oldest);
        $out['listed'] = array_slice($out['listed'], 0, self::DUE_LISTED);
        foreach ($out['listed'] as $invoice) {
            if ($invoice['lane'] !== null) {
                $out['lanes'][$invoice['lane']] = true;
            }
        }
        $out['total'] = round($out['total'], 2);

        return $out;
    }

    /**
     * Les noms des colonnes du planning (praticiens) rencontrées, en une requête.
     *
     * @param  list<int>  $laneIds
     * @param  Collection<int, LignePlanning>  $occupancyLanes
     */
    private function loadLaneNames(array $laneIds, Collection $occupancyLanes): void
    {
        foreach ($occupancyLanes as $lane) {
            $this->laneNames[$lane->id] = ['name' => (string) $lane->nom, 'initials' => $lane->initiales, 'clinic_id' => (int) $lane->clinique_id];
        }
        $ids = collect($laneIds)->reject(fn (int $id): bool => isset($this->laneNames[$id]))->values();
        if ($ids->isEmpty()) {
            return;
        }
        // Les colonnes de toute l'organisation : jamais celles d'une autre
        LignePlanning::withoutGlobalScope('clinique')
            ->whereIn('clinique_id', $this->organisationClinicIds)
            ->whereIn('id', $ids)
            ->get(['id', 'clinique_id', 'nom', 'initiales'])
            ->each(function (LignePlanning $lane): void {
                $this->laneNames[$lane->id] = ['name' => (string) $lane->nom, 'initials' => $lane->initiales, 'clinic_id' => (int) $lane->clinique_id];
            });
    }

    /** @return Totals */
    private function emptyTotals(): array
    {
        return ['revenue' => 0.0, 'count' => 0, 'clinic' => [], 'lane' => [], 'species' => [], 'method' => [], 'payments' => array_fill_keys(self::PAYMENT_KEYS, 0.0)];
    }

    /**
     * Une facture de plus dans les totaux et leurs ventilations.
     *
     * @param  Totals  $totals
     * @param  PaidInvoice  $invoice
     */
    private function addTo(array &$totals, array $invoice): void
    {
        $totals['revenue'] += $invoice['total'];
        $totals['count']++;
        foreach (['clinic', 'lane', 'species', 'method'] as $dimension) {
            $key = $invoice[$dimension];
            $totals[$dimension][$key] ??= ['revenue' => 0.0, 'count' => 0];
            $totals[$dimension][$key]['revenue'] += $invoice['total'];
            $totals[$dimension][$key]['count']++;
        }
        $this->addPayments($totals['payments'], $invoice);
    }

    /**
     * La répartition par moyen : ce qui est passé à la caisse, et la part de
     * l'acompte en ligne ; ensemble, le total de la facture.
     *
     * @param  array<string, float>  $payments
     * @param  PaidInvoice  $invoice
     */
    private function addPayments(array &$payments, array $invoice): void
    {
        $method = isset($payments[$invoice['method']]) && $invoice['method'] !== 'deposit' ? $invoice['method'] : null;
        if ($method !== null) {
            $payments[$method] += $invoice['cashed'];
        }
        $payments['deposit'] += $invoice['deposit'];
    }

    /** @return array{value: float|int|null, previous: float|int|null, change_pct: int|null} */
    private function figure(float|int|null $value, float|int|null $previous): array
    {
        return [
            'value' => is_float($value) ? round($value, 2) : $value,
            'previous' => is_float($previous) ? round($previous, 2) : $previous,
            'change_pct' => $this->change($value, $previous),
        ];
    }

    /** L'évolution en pour cent, arrondie ; aucune sans point de départ */
    private function change(float|int|null $value, float|int|null $previous): ?int
    {
        if ($value === null || $previous === null || abs((float) $previous) < 0.005) {
            return null;
        }

        return (int) round(($value - $previous) / abs((float) $previous) * 100);
    }

    private function points(?int $value, ?int $previous): ?int
    {
        return $value === null || $previous === null ? null : $value - $previous;
    }

    private function basket(float $revenue, int $count): ?float
    {
        return $count > 0 ? round($revenue / $count, 2) : null;
    }

    private function percent(int|float $part, int|float $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    private function speciesLabel(string $key): string
    {
        return Espece::tryFrom($key)?->libelle() ?? 'Sans animal';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $before
     * @return list<array{key: string, total: float, share_pct: int|null, previous: float|null, change_pct: int|null}>
     */
    private function paymentMethods(array $current, ?array $before): array
    {
        $whole = array_sum($current['payments']);

        return array_map(fn (string $key): array => [
            'key' => $key,
            'total' => round($current['payments'][$key], 2),
            'share_pct' => $this->percent($current['payments'][$key], $whole),
            'previous' => $before ? round($before['payments'][$key], 2) : null,
            'change_pct' => $before ? $this->change(round($current['payments'][$key], 2), round($before['payments'][$key], 2)) : null,
        ], self::PAYMENT_KEYS);
    }

    /**
     * Les animaux soignés : factures payées par espèce, la part en nombre de factures.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $before
     * @return list<array<string, mixed>>
     */
    private function species(array $current, ?array $before): array
    {
        return collect($current['species'])
            ->map(fn (array $row, string $key): array => [
                'key' => $key,
                'label' => $this->speciesLabel($key),
                'count' => $row['count'],
                'revenue' => round($row['revenue'], 2),
                'share_pct' => $this->percent($row['count'], $current['count']),
                'previous_count' => $before ? ($before['species'][$key]['count'] ?? 0) : null,
                'change_pct' => $before ? $this->change($row['count'], $before['species'][$key]['count'] ?? 0) : null,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array{label: string, count: int, revenue: float}>  $acts
     * @param  Collection<string, array{label: string, count: int, revenue: float}>|null  $before
     * @return list<array<string, mixed>>
     */
    private function topActs(Collection $acts, ?Collection $before): array
    {
        return $acts
            ->sortBy([['count', 'desc'], ['revenue', 'desc'], ['label', 'asc']])
            ->take(self::TOP_ACTS)
            ->map(fn (array $act): array => [
                ...$act,
                'previous_count' => $before ? ($before[$act['label']]['count'] ?? 0) : null,
                'change_pct' => $before ? $this->change($act['count'], $before[$act['label']]['count'] ?? 0) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * L'âge des factures dues : moins de 30 jours, de 30 à 59, 60 et plus.
     *
     * @param  array{total: float, count: int, aging: array<string, array{total: float, count: int}>, listed: list<DueInvoice>, lanes: array<int, true>}  $due
     * @return array<string, mixed>
     */
    private function due(array $due): array
    {
        return [
            'total' => $due['total'],
            'count' => $due['count'],
            'aging' => collect($due['aging'])->map(fn (array $bucket, string $key): array => [
                'key' => $key,
                'total' => round($bucket['total'], 2),
                'count' => $bucket['count'],
                'share_pct' => $this->percent(round($bucket['total'], 2), $due['total']),
            ])->values()->all(),
            'invoices' => array_map(fn (array $invoice): array => [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'owner_name' => $invoice['owner_name'],
                'patient_name' => $invoice['patient_name'],
                'clinic_id' => $invoice['clinic_id'],
                'lane_id' => $invoice['lane'],
                'practitioner' => $invoice['lane'] === null ? null : ($this->laneNames[$invoice['lane']]['name'] ?? null),
                'practitioner_initials' => $invoice['lane'] === null ? null : ($this->laneNames[$invoice['lane']]['initials'] ?? null),
                'issued_on' => $invoice['issued_on'],
                'age_days' => $invoice['age_days'],
                'amount' => $invoice['amount'],
            ], $due['listed']),
        ];
    }

    /**
     * @param  Collection<int, LignePlanning>  $lanes
     * @param  array{booked: int, open: int, lanes: array<int, array{booked: int, open: int}>, heat: array<string, array{booked: int, open: int}>}  $current
     * @param  array{booked: int, open: int, lanes: array<int, array{booked: int, open: int}>, heat: array<string, array{booked: int, open: int}>}|null  $before
     * @return array<string, mixed>
     */
    private function occupancy(Collection $lanes, array $current, ?array $before): array
    {
        $heat = [];
        foreach ($current['heat'] as $cell => $minutes) {
            if ($minutes['open'] > 0) {
                [$weekday, $hour] = array_map('intval', explode('-', $cell));
                $heat[] = ['weekday' => $weekday, 'hour' => $hour, 'percent' => $this->percent($minutes['booked'], $minutes['open'])];
            }
        }
        usort($heat, fn (array $a, array $b): int => [$a['weekday'], $a['hour']] <=> [$b['weekday'], $b['hour']]);

        return [
            'practitioners' => $lanes->map(function (LignePlanning $lane) use ($current, $before): array {
                $now = $current['lanes'][$lane->id] ?? ['booked' => 0, 'open' => 0];
                $then = $before ? ($before['lanes'][$lane->id] ?? ['booked' => 0, 'open' => 0]) : null;
                $percent = $this->percent($now['booked'], $now['open']);
                $previous = $then ? $this->percent($then['booked'], $then['open']) : null;

                return [
                    'lane_id' => $lane->id,
                    'name' => (string) $lane->nom,
                    'initials' => $lane->initiales,
                    'clinic_id' => (int) $lane->clinique_id,
                    'percent' => $percent,
                    'booked_minutes' => $now['booked'],
                    'open_minutes' => $now['open'],
                    'previous_percent' => $previous,
                    'change_pts' => $this->points($percent, $previous),
                ];
            })->values()->all(),
            'heatmap' => $heat,
        ];
    }

    /**
     * Une ventilation : encaissé, factures et panier par ligne, la période
     * d'avant et l'évolution de chacun, du plus gros encaissé au plus petit.
     *
     * @param  array<string, array{revenue: float, count: int}>  $current
     * @param  array<string, array{revenue: float, count: int}>|null  $before
     * @param  callable(string): string  $label
     * @param  (callable(string): array<string, mixed>)|null  $extra
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $current, ?array $before, callable $label, ?callable $extra = null): array
    {
        $totalRevenue = array_sum(array_column($current, 'revenue'));
        $totalCount = array_sum(array_column($current, 'count'));

        return collect($current)
            ->map(function (array $row, string|int $key) use ($before, $label, $extra, $totalRevenue, $totalCount): array {
                $key = (string) $key;
                $then = $before ? ($before[$key] ?? ['revenue' => 0.0, 'count' => 0]) : null;
                $basket = $this->basket($row['revenue'], $row['count']);
                $basketThen = $then ? $this->basket($then['revenue'], $then['count']) : null;

                return [
                    'key' => $key,
                    'label' => $label($key),
                    ...($extra ? $extra($key) : []),
                    'revenue' => round($row['revenue'], 2),
                    'paid_count' => $row['count'],
                    'average_basket' => $basket,
                    'share_pct' => ['revenue' => $this->percent($row['revenue'], $totalRevenue), 'paid_count' => $this->percent($row['count'], $totalCount)],
                    'previous' => $then ? ['revenue' => round($then['revenue'], 2), 'paid_count' => $then['count'], 'average_basket' => $basketThen] : null,
                    'change' => $then ? [
                        'revenue' => $this->change(round($row['revenue'], 2), round($then['revenue'], 2)),
                        'paid_count' => $this->change($row['count'], $then['count']),
                        'average_basket' => $this->change($basket, $basketThen),
                    ] : null,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Les créneaux d'une période : jours, semaines du lundi au dimanche ou
     * mois du calendrier, le premier et le dernier coupés aux bornes.
     *
     * @return list<array{from: string, to: string}>
     */
    private function slotsOf(CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $slots = [];
        for ($start = $from; $start->lessThanOrEqualTo($to);) {
            $end = match ($granularity) {
                'day' => $start,
                'week' => $start->addDays(7 - $start->dayOfWeekIso),
                default => $start->endOfMonth()->startOfDay(),
            };
            if ($end->greaterThan($to)) {
                $end = $to;
            }
            $slots[] = ['from' => $start->toDateString(), 'to' => $end->toDateString()];
            $start = $end->addDay();
        }

        return $slots;
    }

    /**
     * Par semaine, la comparaison reprend la coupe de la période, créneau par
     * créneau (mêmes longueurs) : les barres et les points se répondent ;
     * par jour et par mois, ses propres jours et mois (vérification du 06.11.2026).
     *
     * @param  list<array{from: string, to: string}>  $slots
     * @return list<array{from: string, to: string}>
     */
    private function comparisonSlotsOf(array $slots, CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        if ($granularity !== 'week') {
            return $this->slotsOf($from, $to, $granularity);
        }
        $out = [];
        $start = $from;
        foreach ($slots as $slot) {
            if ($start->greaterThan($to)) {
                break;
            }
            $end = $start->addDays((int) CarbonImmutable::parse($slot['from'])->diffInDays(CarbonImmutable::parse($slot['to'])));
            if ($end->greaterThan($to)) {
                $end = $to;
            }
            $out[] = ['from' => $start->toDateString(), 'to' => $end->toDateString()];
            $start = $end->addDay();
        }

        return $out;
    }

    /**
     * @param  list<array{from: string, to: string}>  $slots
     * @param  list<SlotTotals>  $totals
     * @return list<array<string, mixed>>
     */
    private function series(array $slots, array $totals, bool $withPayments): array
    {
        return array_map(fn (array $slot, int $index): array => [
            'from' => $slot['from'],
            'to' => $slot['to'],
            'revenue' => round($totals[$index]['revenue'], 2),
            'paid_count' => $totals[$index]['count'],
            'average_basket' => $this->basket(round($totals[$index]['revenue'], 2), $totals[$index]['count']),
            ...($withPayments ? ['payments' => array_map(fn (float $amount): float => round($amount, 2), $totals[$index]['payments'])] : []),
        ], $slots, array_keys($slots));
    }

    /**
     * Le créneau d'un jour, par dichotomie (les créneaux se suivent).
     *
     * @param  list<array{from: string, to: string}>  $slots
     */
    private function slotIndex(array $slots, string $day): int
    {
        [$low, $high] = [0, count($slots) - 1];
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($slots[$middle]['to'] < $day) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * Toute l'histoire, pour l'aperçu sous le graphique : l'encaissé depuis
     * la première facture payée jusqu'à aujourd'hui, par jour ou par semaine.
     *
     * @return array{granularity: string, first_day: string, last_day: string, points: list<array{from: string, to: string, revenue: float}>}
     */
    private function history(CarbonImmutable $today): array
    {
        $base = fn () => $this->invoicesWithTotal(['factures.id', 'factures.payee_le'])
            ->where('statut', StatutFacture::Payee)
            ->whereNotNull('payee_le')
            ->where('payee_le', '<', $this->startOf($today->addDay()));

        // Le premier jour d'abord (une requête), puis les montants lus par paquets de mille
        $firstPaid = Facture::query()->whereIn('clinique_id', $this->clinicIds)->where('statut', StatutFacture::Payee)->whereNotNull('payee_le')->min('payee_le');
        $first = $firstPaid === null ? $today : CarbonImmutable::parse((string) $this->localDay((string) $firstPaid));
        if ($first->greaterThan($today)) {
            $first = $today;
        }
        $granularity = $first->diffInDays($today) + 1 <= self::HISTORY_DAYS_UP_TO ? 'day' : 'week';
        $points = $this->slotsOf($first, $today, $granularity);
        $sums = array_fill(0, count($points), 0.0);
        foreach ($this->inChunks($base()) as $row) {
            $sums[$this->slotIndex($points, (string) $this->localDay((string) $row->payee_le))] += round((float) $row->lines_total, 2);
        }

        return [
            'granularity' => $granularity,
            'first_day' => $first->toDateString(),
            'last_day' => $today->toDateString(),
            'points' => array_map(fn (array $point, int $index): array => [...$point, 'revenue' => round($sums[$index], 2)], $points, array_keys($points)),
        ];
    }
}
