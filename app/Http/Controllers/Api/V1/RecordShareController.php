<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Mail\RecordShareLink;
use App\Models\Client;
use App\Models\Patient;
use App\Models\RecordShare;
use App\Models\RecordShareEvent;
use App\Services\Hospitalisation\OwnerMessages;
use App\Services\Patients\RecordShareItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

    public function store(Request $request, Patient $patient, RecordShareItems $items, OwnerMessages $messages): JsonResponse
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
        ]);

        // Seulement des pièces de cet animal, partageables aujourd'hui
        $allowed = collect($items->candidates($patient, null, null))->flatMap(fn (array $g) => array_map(fn (array $i) => $i['type'].':'.$i['id'], $g['items']))->flip();
        $chosen = collect($data['items'])->map(fn (array $i) => ['type' => $i['type'], 'id' => (int) $i['id']])->unique(fn (array $i) => $i['type'].':'.$i['id'])->values();
        if ($chosen->contains(fn (array $i) => ! $allowed->has($i['type'].':'.$i['id']))) {
            throw ValidationException::withMessages(['items' => 'Une pièce n’est pas de ce dossier, ou ne peut pas être partagée.']);
        }

        $token = Str::random(48);
        $employe = app(ContexteClinique::class)->employe();
        $share = RecordShare::query()->create([
            'organisation_id' => $patient->organisation_id,
            'patient_id' => $patient->id,
            'kind' => 'link',
            'token_hash' => RecordShare::hash($token),
            'recipient_email' => $data['recipient_email'] ?? null,
            'items' => $chosen->all(),
            'include_visit_notes' => (bool) ($data['visit_notes'] ?? false),
            'period_from' => $data['from'] ?? null,
            'period_to' => $data['to'] ?? null,
            'expires_at' => now()->addDays((int) $data['days']),
            'created_by_id' => $employe?->id,
            'created_by_name' => $employe->nom ?? $request->user()?->name,
        ]);
        $url = self::url($token);
        $clinique = app(ContexteClinique::class)->clinique();

        // L'e-mail qui n'a pas pu partir est dit à l'écran : le lien reste, à copier
        $isEmailSent = null;
        if ($share->recipient_email !== null && $clinique !== null) {
            try {
                Mail::to($share->recipient_email)->send(new RecordShareLink($share, $clinique, $patient, $url));
                $isEmailSent = true;
            } catch (Throwable $e) {
                report($e);
                $isEmailSent = false;
            }
        }
        $this->tellOwner($patient, $share, $messages);

        return response()->json(['data' => [...self::present($share->load(['events', 'clinique'])), 'url' => $url, 'is_email_sent' => $isEmailSent]], 201);
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

    /** Le propriétaire qui a un compte apprend, dans ses messages, avec qui le dossier est partagé */
    private function tellOwner(Patient $patient, RecordShare $share, OwnerMessages $messages): void
    {
        $client = $patient->client_id ? Client::query()->find($patient->client_id) : null;
        if ($client?->user_id === null) {
            return;
        }
        $until = $share->expiryDay();
        $text = 'Le dossier de '.$patient->nom.' a été partagé par '.($share->created_by_name ?: 'la clinique')
            .($share->recipient_email ? ' avec '.$share->recipient_email : ' par un lien provisoire')
            .', jusqu’au '.$until.'.';
        [$message, $first] = DB::transaction(fn () => $messages->createFor((int) $share->clinique_id, $client, $text));
        $messages->notifyFor((int) $share->clinique_id, $client, $message, $text, $first);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/share/'.$token;
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
            'opened_at' => $events->firstWhere('kind', 'opened')?->at?->toIso8601String(),
            'last_opened_at' => $events->where('kind', 'opened')->last()?->at?->toIso8601String(),
            'downloaded_at' => $events->where('kind', 'downloaded')->map(fn (RecordShareEvent $e) => $e->at->toIso8601String())->values()->all(),
        ];
    }
}
