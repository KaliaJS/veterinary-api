<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ObservationKind;
use App\Http\Controllers\Controller;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Services\Hospitalisation\StayRound;
use App\Services\Hospitalisation\StaySheets;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * La ronde de l'hospitalisation (03.11.2026) : pour les séjours en cours de
 * la clinique du jeton, ce qui se passe entre deux jours de feuille. Les
 * jours défilent sans fin dans les deux sens ; l'écran demande les jours
 * qu'il montre, deux mois au plus à la fois. La feuille d'aujourd'hui
 * s'ouvre d'elle-même à la lecture.
 */
class StayRoundController extends Controller
{
    private const int MAX_DAYS = 62;

    public function __construct(private StaySheets $sheets, private StayRound $round) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sejour::class);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sejour_id' => ['sometimes', 'integer'],
        ]);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Deux mois au plus à la fois.']);
        }

        // Un séjour demandé par son numéro se relit même sorti (depuis le dossier de l'animal)
        $stays = Sejour::query()
            ->when($data['sejour_id'] ?? null, fn ($q, int $id) => $q->whereKey($id), fn ($q) => $q->enCours())
            ->with(['clinique', 'treatments.product'])
            ->orderBy('box')->get();

        $stays->each(fn (Sejour $sejour) => $this->sheets->open($sejour));
        $today = $this->sheets->todayIn(app(ContexteClinique::class)->clinique()?->fuseau_horaire);

        return response()->json(['data' => [
            'today' => $today,
            // Ce qu'on note au chevet et ses valeurs courtes, avec leur ton
            'observation_kinds' => ObservationKind::catalog(),
            'stays' => $stays->map(fn (Sejour $sejour): array => $this->round->range($sejour, $data['from'], $data['to']))->values()->all(),
            ...$this->counts(),
        ]]);
    }

    /**
     * Ce qui presse sur la feuille du jour, tous animaux confondus
     * (03.11.2026) : en retard (passé de plus de 5 minutes) et dans l'heure ;
     * et jusqu'à quand ces chiffres restent vrais (la prochaine prise qui
     * change d'état, ou 06:00) : l'écran les redemande à cet instant.
     *
     * @return array{counts: array{late: int, soon: int}, counts_until: ?string}
     */
    private function counts(): array
    {
        $now = CarbonImmutable::now()->utc();
        $late = 0;
        $soon = 0;
        $next = [];
        foreach (Sejour::query()->enCours()->with('clinique')->get() as $sejour) {
            $today = $this->sheets->today($sejour);
            $next[] = $this->sheets->instant($sejour, CarbonImmutable::parse($today)->addDay()->toDateString(), StaySheets::START);
            $pending = SejourSoin::query()->where('sejour_id', $sejour->id)->where('fait', false)->whereNull('skipped_at')
                ->whereDate('jour', '>=', $today)->whereDate('jour', '<=', CarbonImmutable::parse($today)->addDay()->toDateString())->get()
                ->filter(fn (SejourSoin $s): bool => StaySheets::sheetDayOf($s->jour->toDateString(), $s->prevu_a) === $today);
            foreach ($pending as $soin) {
                $at = $this->sheets->instant($sejour, $soin->jour->toDateString(), $soin->prevu_a);
                if ($at->lt($now->subMinutes(5))) {
                    $late++;
                } elseif ($at->lte($now->addHour())) {
                    $soon++;
                    $next[] = $at->addMinutes(5);
                } else {
                    $next[] = $at->subHour();
                }
            }
        }
        $until = collect($next)->filter(fn (CarbonImmutable $t): bool => $t->gt($now))->min();

        return ['counts' => ['late' => $late, 'soon' => $soon], 'counts_until' => $until?->utc()->toIso8601String()];
    }

    /** Les chiffres seuls, redemandés quand ils changent d'eux-mêmes */
    public function counters(): JsonResponse
    {
        $this->authorize('viewAny', Sejour::class);

        return response()->json(['data' => [
            'today' => $this->sheets->todayIn(app(ContexteClinique::class)->clinique()?->fuseau_horaire),
            ...$this->counts(),
        ]]);
    }

    /** Le séjour jour par jour (« depuis 3 jours ») */
    public function days(Sejour $sejour): JsonResponse
    {
        $this->authorize('view', $sejour);
        $this->sheets->open($sejour);

        return response()->json(['data' => $this->round->days($sejour->load(['veterinaire' => fn ($q) => $q->withoutGlobalScope('clinique')]))]);
    }
}
