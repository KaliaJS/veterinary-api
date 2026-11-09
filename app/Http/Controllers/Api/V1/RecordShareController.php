<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\RecordShare;
use App\Models\RecordShareEvent;
use App\Services\Patients\RecordShareItems;
use App\Services\Patients\RecordShares;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Partager le dossier médical (demande de Jim, 09.11.2026), côté clinique :
 * les pièces d'une période (les 6 derniers mois par défaut, ou tout), une
 * pièce sous sa forme imprimable et ses fichiers (pour l'export en PDF fait
 * dans le navigateur), puis les liens provisoires de l'animal : créer
 * (envoyé au confrère ou à copier ; le propriétaire est prévenu), lister
 * avec leurs ouvertures et téléchargements, couper. Droit Patients.
 */
class RecordShareController extends Controller
{
    /** Les refus dits en français, sous leur champ */
    private const MESSAGES = [
        'recipient_email.email' => 'Cette adresse e-mail n’est pas valable.',
        'recipient_email.max' => 'Cette adresse e-mail est trop longue.',
    ];

    public function items(Request $request, Patient $patient, RecordShareItems $items): JsonResponse
    {
        $this->authorize('view', $patient);
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $items->candidates($patient, $data['from'] ?? null, $data['to'] ?? null)]);
    }

    /** `notes=1` : les notes des visites partent aussi (au choix du vétérinaire) */
    public function item(Request $request, Patient $patient, string $type, int $id, RecordShareItems $items): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json(['data' => $items->printable($patient, $type, $id, ['visit_notes' => $request->boolean('notes')]) ?? abort(404)]);
    }

    public function file(Patient $patient, string $type, int $id, int $n, RecordShareItems $items): StreamedResponse
    {
        $this->authorize('view', $patient);
        $file = $items->file($patient, $type, $id, $n) ?? abort(404);

        return Storage::disk('prive')->download($file['path'], $file['name'], ['Content-Type' => $file['mime']]);
    }

    /** Les liens de l'animal, de toutes les cliniques de l'organisation : le dossier est commun */
    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $shares = RecordShare::withoutGlobalScopes()->where('organisation_id', $patient->organisation_id)->where('patient_id', $patient->id)
            ->where('kind', 'link')->with(['events', 'clinique'])->latest('id')->limit(50)->get();

        return response()->json(['data' => $shares->map(fn (RecordShare $s) => self::present($s))->values()]);
    }

    public function store(Request $request, Patient $patient, RecordShares $shares): JsonResponse
    {
        $this->authorize('update', $patient);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.type' => ['required', 'string', Rule::in(RecordShareItems::TYPES)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'days' => ['required', 'integer', Rule::in(RecordShare::DURATIONS)],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'visit_notes' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], self::MESSAGES);

        $created = $shares->create($patient, $data['items'], (int) $data['days'], $data['recipient_email'] ?? null, (bool) ($data['visit_notes'] ?? false), $data['from'] ?? null, $data['to'] ?? null, $request->user()?->name);

        return response()->json(['data' => [...self::present($created['share']->load(['events', 'clinique'])), 'url' => $created['url'], 'is_email_sent' => $created['is_email_sent']]], 201);
    }

    /** Couper un lien : depuis toute clinique de l'organisation qui tient le dossier de l'animal */
    public function revoke(Request $request, int $share): JsonResponse
    {
        $share = RecordShare::withoutGlobalScopes()->where('organisation_id', app(ContexteClinique::class)->organisationId())->findOrFail($share);
        $this->authorize('update', $share->patient ?? abort(404));
        if ($share->revoked_at === null) {
            $employe = app(ContexteClinique::class)->employe();
            $share->forceFill(['revoked_at' => now(), 'revoked_by_name' => $employe->nom ?? $request->user()?->name])->save();
        }

        return response()->json(['data' => self::present($share->load(['events', 'clinique']))]);
    }

    /** @return array<string, mixed> */
    public static function present(RecordShare $share): array
    {
        $events = $share->relationLoaded('events') ? $share->events : collect();

        return [
            'id' => $share->id,
            'recipient_email' => $share->recipient_email,
            'clinic' => $share->relationLoaded('clinique') ? $share->clinique?->nom : null,
            'include_visit_notes' => $share->include_visit_notes,
            'items_count' => count($share->items ?? []),
            'period_from' => $share->period_from?->toDateString(),
            'period_to' => $share->period_to?->toDateString(),
            'expires_at' => $share->expires_at->toIso8601String(),
            'created_at' => $share->created_at?->toIso8601String(),
            'created_by_name' => $share->created_by_name,
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'revoked_by_name' => $share->revoked_by_name,
            'is_active' => $share->isActive(),
            'full_downloads' => $share->full_downloads,
            'downloads_max' => RecordShare::MAX_DOWNLOADS_PER_LINK,
            'openings' => $share->openings,
            'openings_max' => $share->openings_max,
            'opened_at' => $events->firstWhere('kind', 'opened')?->at?->toIso8601String(),
            'last_opened_at' => $events->where('kind', 'opened')->last()?->at?->toIso8601String(),
            'downloaded_at' => $events->where('kind', 'downloaded')->map(fn (RecordShareEvent $e) => $e->at->toIso8601String())->values()->all(),
        ];
    }
}
