<?php

namespace Database\Seeders;

use App\Enums\PainScale;
use App\Models\Employe;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourConstante;
use App\Models\SejourSoin;
use App\Models\StayObservation;
use App\Models\StayPainScore;
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Services\Hospitalisation\StayRound;
use App\Services\Hospitalisation\StaySheets;
use Carbon\CarbonImmutable;

/**
 * Données de démo de la ronde (03.11.2026), écrites autour de maintenant :
 * pour chaque séjour en cours, son entrée (il y a 0 à 3 jours selon le
 * motif), ses traitements, et chaque jour passé sa feuille remplie (soins
 * faits, médicaments donnés, un non donné avec sa raison, douleurs sur
 * l'échelle de l'espèce, repas et éliminations, constantes, qui l'a fait),
 * aujourd'hui jusqu'à l'heure qu'il est (un soin en retard), les rapports
 * des jours passés signés. Utilisé par HospitalisationSeeder et par la
 * commande locale hospitalisation:demo.
 */
final class HospitalisationDemo
{
    /**
     * Par mot du motif : [jours depuis l'entrée, minute d'entrée, perfusion,
     * traitements [libellé, dose, heures, référence produit], soins
     * quotidiens [minute, acte], soins du jour seulement [minute, acte],
     * vomit les premiers jours].
     *
     * @var array<string, array{0: int, 1: int, 2: ?string, 3: list<array{0: string, 1: string, 2: list<int>, 3: ?string, 4: int|float|null}>, 4: list<array{0: int, 1: string}>, 5: list<array{0: int, 1: string}>, 6: bool}>
     */
    private const array SCENARIOS = [
        'rénale' => [3, 555, 'NaCl 0,9 % · 80 ml/h', [
            ['Maropitant', '1 mg/kg IV', [600, 1320], null, null],
            ['Oméprazole', '1 mg/kg IV', [480], null, null],
        ], [[420, 'Prise de sang'], [480, 'Pesée'], [840, 'T°, FC, FR'], [1080, 'Prise de sang'], [1200, 'Contrôle perfusion']], [], true],
        'anesthésie' => [0, 570, null, [
            ['Buprénorphine', '0,02 mg/kg IV', [660, 1140], 'MED-0912', 0.3],
        ], [[660, 'Réveil complet'], [900, 'T° et gencives']], [[1020, 'Sortie prévue']], false],
        'Gastro' => [1, 1090, 'Ringer lactate · 60 ml/h', [
            ['Métronidazole', '10 mg/kg IV', [480, 960], null, null],
            ['Maropitant', '1 mg/kg IV', [600], null, null],
        ], [[480, 'Repas fractionné'], [540, 'Contrôle hydratation'], [660, 'Repas fractionné'], [840, 'Repas fractionné'], [1200, 'Repas fractionné']], [], true],
        'tibia' => [2, 660, null, [
            ['Méloxicam', '0,05 mg/kg PO', [540], 'MED-0114', 0.4],
            ['Buprénorphine', '0,02 mg/kg IV', [480, 1200], 'MED-0912', 0.3],
        ], [[780, 'Contrôle confort et immobilisation']], [[1200, 'À jeun dès 20:00']], false],
        'Pancréatite' => [1, 990, 'Ringer lactate · 52 ml/h', [
            ['Maropitant', '1 mg/kg IV', [600], null, null],
            ['Méthadone', '0,2 mg/kg IV', [480, 960, 1320], 'MED-0910', 0.5],
        ], [[500, 'Contrôle douleur abdominale'], [880, 'Petite ration'], [1060, 'T° et lipase']], [], true],
        'coussinet' => [0, 560, null, [
            ['Amoxicilline', '12,5 mg/kg PO', [540, 1260], 'MED-0087', 1],
        ], [[590, 'Contrôle pansement'], [820, 'Renouvellement pansement']], [[1040, 'Visite de sortie']], false],
        'Diabète' => [2, 495, null, [
            ['Insuline', '0,5 UI/kg SC', [480, 1200], 'MED-0166', 6],
        ], [[480, 'Glycémie'], [660, 'Glycémie'], [840, 'Glycémie'], [1020, 'Glycémie'], [1200, 'Glycémie']], [], false],
    ];

    /** Un séjour sans motif connu : un antalgique et deux contrôles */
    private const array GENERIC = [null, null, null, [
        ['Méloxicam', '0,2 mg PO', [480, 1200], 'MED-0114', 0.2],
    ], [[480, 'Pesée'], [720, 'Gavage'], [1080, 'Gavage']], [], false];

    /** Référence → [contenance d'une unité, unité de dose] */
    private const array CONTENTS = [
        'MED-0114' => [100, 'ml'],
        'MED-0087' => [20, 'cp'],
        'MED-0166' => [1000, 'UI'],
        'MED-0910' => [10, 'ml'],
        'MED-0912' => [10, 'ml'],
    ];

    private const array MEALS = [['rien', 'peu', 'rien'], ['peu', 'moitié', 'peu'], ['moitié', 'moitié', 'tout'], ['tout', 'moitié', 'tout']];

    public function __construct(private StaySheets $sheets = new StaySheets) {}

    /** Réécrit la ronde d'un séjour en cours autour de maintenant */
    public function fill(Sejour $sejour): void
    {
        [$daysAgo, $minute, $drip, $treatments, $daily, $todayOnly, $vomits] = $this->scenario($sejour->motif);
        $tz = $this->sheets->timezone($sejour);
        $now = CarbonImmutable::now($tz);

        if ($daysAgo !== null) {
            $admis = $now->startOfDay()->subDays($daysAgo)->addMinutes($minute);
            // Entré aujourd'hui après maintenant : une heure avant
            if ($admis->gt($now)) {
                $admis = $now->subHour();
            }
            $sejour->forceFill(['admis_le' => $admis->utc(), 'perfusion' => $drip ?? $sejour->perfusion])->save();
        }
        // Le propriétaire qui a confié l'animal et qui l'a admis (03.11.2026)
        $sejour->forceFill([
            'client_id' => $sejour->client_id ?? $sejour->patient()->withoutGlobalScopes()->value('client_id'),
            'admitted_by_name' => $sejour->admitted_by_name ?? $sejour->veterinaire()->withoutGlobalScopes()->value('nom'),
        ])->save();
        $sejour->refresh();

        $this->wipe($sejour);
        $staff = $this->staff($sejour);
        $products = Produit::withoutGlobalScopes()->where('organisation_id', $sejour->patient()->withoutGlobalScopes()->value('organisation_id'))
            ->pluck('id', 'reference');
        // Ce que contient une unité des produits de démo (médicaments à la dose, 03.11.2026)
        foreach (self::CONTENTS as $reference => [$contenance, $unite]) {
            Produit::withoutGlobalScopes()->where('reference', $reference)->whereNull('content_per_unit')
                ->update(['content_per_unit' => $contenance, 'dose_unit' => $unite]);
        }
        foreach ($treatments as [$label, $dose, $times, $ref, $amount]) {
            StayTreatment::create([
                'sejour_id' => $sejour->id,
                'label' => $label,
                'dose' => $dose,
                'times' => $times,
                'product_id' => $ref !== null ? ($products[$ref] ?? null) : null,
                'dose_amount' => $ref !== null ? $amount : null,
                'starts_at' => $sejour->admis_le,
                'created_by_name' => $staff[0]['name'],
                'created_by_id' => $staff[0]['user_id'],
            ]);
        }

        $scale = PainScale::forSpecies($sejour->patient()->withoutGlobalScopes()->first()?->espece);
        $first = $this->sheets->admissionDay($sejour);
        $today = $this->sheets->today($sejour);
        $index = 0;
        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $today; $day = $day->addDay(), $index++) {
            $this->day($sejour, $day->toDateString(), $index, $day->toDateString() === $today, $daily, $todayOnly, $vomits, $scale, $staff, $now);
        }
    }

    /** @return array{0: ?int, 1: ?int, 2: ?string, 3: list<array{0: string, 1: string, 2: list<int>, 3: ?string, 4: int|float|null}>, 4: list<array{0: int, 1: string}>, 5: list<array{0: int, 1: string}>, 6: bool} */
    private function scenario(string $motif): array
    {
        foreach (self::SCENARIOS as $word => $scenario) {
            if (mb_stripos($motif, $word) !== false) {
                return $scenario;
            }
        }

        return self::GENERIC;
    }

    private function wipe(Sejour $sejour): void
    {
        SejourSoin::query()->where('sejour_id', $sejour->id)->delete();
        StayTreatment::query()->where('sejour_id', $sejour->id)->delete();
        StaySheet::query()->where('sejour_id', $sejour->id)->delete();
        StayPainScore::query()->where('sejour_id', $sejour->id)->delete();
        StayObservation::query()->where('sejour_id', $sejour->id)->delete();
        SejourConstante::query()->where('sejour_id', $sejour->id)->delete();
    }

    /** @return list<array{name: string, user_id: ?int}> l'équipe de la clinique, vétérinaires et assistantes */
    private function staff(Sejour $sejour): array
    {
        $staff = Employe::withoutGlobalScopes()
            ->whereHas('cliniques', fn ($q) => $q->withoutGlobalScopes()->where('cliniques.id', $sejour->clinique_id))
            ->whereNotNull('user_id')->orderBy('id')->get(['id', 'nom', 'user_id'])
            ->map(fn (Employe $e): array => ['name' => $e->nom, 'user_id' => $e->user_id])->values()->all();

        return $staff !== [] ? $staff : [['name' => 'Équipe de garde', 'user_id' => null]];
    }

    /**
     * @param  list<array{0: int, 1: string}>  $daily
     * @param  list<array{0: int, 1: string}>  $todayOnly
     * @param  list<array{name: string, user_id: ?int}>  $staff
     */
    private function day(Sejour $sejour, string $sheetDay, int $index, bool $isToday, array $daily, array $todayOnly, bool $vomits, PainScale $scale, array $staff, CarbonImmutable $now): void
    {
        $nowUtc = $now->utc();
        $next = CarbonImmutable::parse($sheetDay)->addDay()->toDateString();
        $calendar = fn (int $m): string => $m < StaySheets::START ? $next : $sheetDay;
        $at = fn (int $m): CarbonImmutable => $this->sheets->instant($sejour, $calendar($m), $m);
        $inStay = fn (int $m): bool => $at($m)->gte($sejour->admis_le);
        // L'équipe du jour : trois personnes, une autre équipe le lendemain
        $team = array_map(fn (int $i): array => $staff[($index * 2 + $i) % count($staff)], range(0, min(2, count($staff) - 1)));
        $who = fn (int $k): array => $team[$k % count($team)];
        $isPast = fn (int $m): bool => $at($m)->lt($nowUtc->subMinutes(10));
        $leftLate = false;

        // Les soins de la journée
        foreach ([...$daily, ...($isToday ? $todayOnly : [])] as $k => [$m, $label]) {
            if (! $inStay($m)) {
                continue;
            }
            $soin = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => $calendar($m), 'prevu_a' => $m, 'acte' => $label]);
            // Aujourd'hui, le dernier soin passé de moins de deux heures reste en retard
            $late = $isToday && ! $leftLate && $isPast($m) && $at($m)->gt($nowUtc->subHours(3));
            if ($isPast($m) && ! $late) {
                $soin->forceFill(['fait' => true, 'fait_le' => $at($m)->addMinutes(4 + ($k * 3) % 9), 'fait_par_id' => $who($k)['user_id'], 'done_by_name' => $who($k)['name']])->save();
            }
            $leftLate = $leftLate || $late;
        }

        // Les médicaments : ceux des traitements, donnés à l'heure ; hier, un non donné
        $this->sheets->materialize($sejour, $sheetDay);
        $doses = SejourSoin::query()->where('sejour_id', $sejour->id)->whereNotNull('treatment_id')->get()
            ->filter(fn (SejourSoin $s): bool => StaySheets::sheetDayOf($s->jour->toDateString(), $s->prevu_a) === $sheetDay)
            ->sortBy(fn (SejourSoin $s): int => $s->prevu_a < StaySheets::START ? $s->prevu_a + 1440 : $s->prevu_a)->values();
        foreach ($doses as $k => $dose) {
            if (! $isPast($dose->prevu_a)) {
                continue;
            }
            // Aujourd'hui, une prise de moins de deux heures attend encore
            if ($isToday && $at($dose->prevu_a)->gt($nowUtc->subHours(2))) {
                continue;
            }
            $giver = $who($k + 1);
            if (! $isToday && $index === 0 && $k === 0 && $doses->count() > 1) {
                $dose->forceFill(['skipped_at' => $at($dose->prevu_a)->addMinutes(6), 'skipped_by_id' => $giver['user_id'], 'skipped_by_name' => $giver['name'], 'skip_reason' => 'À jeun pour l’examen, redonné au repas suivant'])->save();

                continue;
            }
            // Le prix et la contenance du jour de la prise, figés sur elle
            $produit = $dose->produit_id !== null ? Produit::withoutGlobalScopes()->find($dose->produit_id) : null;
            $dose->forceFill(['fait' => true, 'fait_le' => $at($dose->prevu_a)->addMinutes(3 + $k * 2), 'fait_par_id' => $giver['user_id'], 'done_by_name' => $giver['name'], 'unit_price' => $produit?->prix, 'content_per_unit' => $produit?->content_per_unit])->save();
        }

        // La douleur, qui baisse de jour en jour
        $max = $scale->max();
        foreach ([480, 960, 1320] as $k => $m) {
            if (! $inStay($m) || ! $isPast($m)) {
                continue;
            }
            $score = max(0, min($max, (int) round($max * (0.42 - 0.09 * $index - 0.05 * $k))));
            $by = $who($k + 2);
            $local = $this->sheets->local($sejour, $at($m));
            StayPainScore::create(['sejour_id' => $sejour->id, 'day' => $local['day'], 'minute' => $m, 'scale' => $scale, 'score' => $score, 'max' => $max, 'threshold' => $scale->threshold(), 'by_id' => $by['user_id'], 'by_name' => $by['name']]);
        }

        // Repas, boisson, urine, selles, vomissements
        $meals = self::MEALS[min($index, count(self::MEALS) - 1)];
        $notes = [
            [470, 'eats', $meals[0]], [600, 'drinks', $index === 0 ? 'peu' : 'oui'], [660, 'urine', 'oui'],
            [740, 'eats', $meals[1]], [900, 'stool', $index === 0 ? 'molles' : 'normales'], [1020, 'urine', 'oui'], [1150, 'eats', $meals[2]],
        ];
        if ($vomits && $index <= 1) {
            $notes[] = [690, 'vomit', $index === 0 ? 'plusieurs fois' : 'une fois'];
        }
        foreach ($notes as $k => [$m, $kind, $value]) {
            if (! $inStay($m) || ! $isPast($m)) {
                continue;
            }
            $by = $who($k);
            StayObservation::create(['sejour_id' => $sejour->id, 'day' => $calendar($m), 'minute' => $m, 'kind' => $kind, 'value' => $value, 'by_id' => $by['user_id'], 'by_name' => $by['name']]);
        }

        // Les constantes, matin et soir
        foreach ([[450, 38.9 - 0.2 * $index, 120 - 6 * $index, 30 - $index], [1140, 38.7 - 0.2 * $index, 112 - 5 * $index, 28 - $index]] as [$m, $t, $fc, $fr]) {
            if ($inStay($m) && $isPast($m)) {
                $by = $who(1);
                SejourConstante::create(['sejour_id' => $sejour->id, 'jour' => $calendar($m), 'mesure_a' => $m, 'temperature' => round(max(37.8, $t), 1), 'fc' => max(80, $fc), 'fr' => max(18, $fr), 'by_id' => $by['user_id'], 'by_name' => $by['name']]);
            }
        }

        // Les jours passés : le rapport relu et signé le soir même
        $sheet = StaySheet::query()->where('sejour_id', $sejour->id)->whereDate('day', $sheetDay)->first()
            ?? StaySheet::create(['sejour_id' => $sejour->id, 'day' => $sheetDay, 'opened_at' => $at(StaySheets::START)]);
        if (! $isToday) {
            $signer = $who(1);
            $sheet->forceFill([
                'report_status' => StaySheet::REPORT_SIGNED,
                'report_text' => $this->reportText($sejour, $sheetDay),
                'report_drafted_at' => $at(1400),
                'report_signed_at' => $at(1420),
                'report_signed_by_id' => $signer['user_id'],
                'report_signed_by_name' => $signer['name'],
            ])->save();
        }
    }

    /** Le rapport d'un jour de démo, tiré de sa feuille (jamais inventé) */
    private function reportText(Sejour $sejour, string $sheetDay): string
    {
        $round = app(StayRound::class)->range($sejour, $sheetDay, $sheetDay);
        $cares = collect($round['cares']);
        $meds = $cares->where('is_medication', true);
        $given = $meds->where('status', 'done');
        $skipped = $meds->where('status', 'skipped');
        $pains = collect($round['pains']);
        $meals = collect($round['observations'])->where('kind', 'eats')->pluck('value')->all();

        $lines = [];
        if ($meals !== []) {
            $lines[] = 'Alimentation : '.implode(', puis ', $meals).'.';
        }
        if ($given->isNotEmpty()) {
            $lines[] = $given->count().' médicament'.($given->count() > 1 ? 's donnés' : ' donné').' sur '.$meds->count().' prévus ('.$given->map(fn (array $m): string => $m['label'].' à '.$m['done_time'].' par '.$m['by'])->implode(', ').').';
        }
        foreach ($skipped as $m) {
            $lines[] = $m['label'].' de '.StayRound::hm($m['minute']).' non donné : '.mb_strtolower((string) $m['reason']).'.';
        }
        if ($pains->isNotEmpty()) {
            $last = $pains->last();
            $lines[] = 'Douleur '.$pains->pluck('score')->implode(', puis ').' sur '.$last['max'].', '.($last['is_above'] ? 'encore au-dessus du seuil de traitement.' : 'sous le seuil de traitement en fin de journée.');
        }

        return implode("\n\n", $lines);
    }
}
