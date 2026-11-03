<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ObservationKind;
use App\Enums\PainScale;
use App\Http\Controllers\Controller;
use App\Models\Sejour;
use App\Services\Hospitalisation\StayRound;
use App\Services\Hospitalisation\StaySheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ce qu'on note au chevet (03.11.2026) : la douleur sur l'échelle de
 * l'espèce, et mange, boit, urine, selles, vomit. À l'heure réelle du
 * geste (maintenant, ou une heure passée ; sur une feuille close, une note
 * tardive marquée), au nom de qui le note.
 */
class StayNoteController extends Controller
{
    /**
     * L'heure réelle du geste, si ce n'est pas maintenant : le jour de la
     * feuille et la minute (une note tardive sur une feuille close).
     */
    private const array WHEN = [
        'sheet_day' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'time' => ['sometimes', 'nullable', 'integer', 'between:0,1439', 'required_with:sheet_day'],
    ];

    public function __construct(private StaySheets $sheets, private StayRound $round) {}

    public function pain(Request $request, Sejour $sejour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $scale = PainScale::forSpecies($sejour->patient?->espece);
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:0,'.$scale->max()],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...self::WHEN,
        ], ['score.between' => 'Sur cette échelle ('.$scale->label().'), le score va de 0 à '.$scale->max().'.']);

        $when = $this->sheets->gestureTime($sejour, $data['sheet_day'] ?? null, $data['time'] ?? null);
        $pain = $sejour->painScores()->create([
            'day' => $when['day'],
            'minute' => $when['minute'],
            'scale' => $scale,
            'score' => $data['score'],
            'max' => $scale->max(),
            'threshold' => $scale->threshold(),
            'note' => $data['note'] ?? null,
            'by_id' => $request->user()?->id,
            'by_name' => $this->sheets->authorName($request->user()),
            'late_noted_at' => $when['late'] ? now() : null,
        ]);
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return response()->json(['data' => $this->round->pain($pain->refresh())], 201);
    }

    public function observation(Request $request, Sejour $sejour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ObservationKind::class)],
            'value' => ['required', 'string', 'max:40'],
            ...self::WHEN,
        ], ['kind.*' => 'Mange, boit, urine, selles ou vomit.']);

        $when = $this->sheets->gestureTime($sejour, $data['sheet_day'] ?? null, $data['time'] ?? null);
        $observation = $sejour->observations()->create([
            'day' => $when['day'],
            'minute' => $when['minute'],
            'kind' => $data['kind'],
            'value' => trim($data['value']),
            'by_id' => $request->user()?->id,
            'by_name' => $this->sheets->authorName($request->user()),
            'late_noted_at' => $when['late'] ? now() : null,
        ]);
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return response()->json(['data' => $this->round->observation($observation->refresh())], 201);
    }
}
