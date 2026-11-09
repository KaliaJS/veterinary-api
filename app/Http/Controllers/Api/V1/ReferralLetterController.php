<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReferralLetterResource;
use App\Models\EnregistrementConsultation;
use App\Models\Patient;
use App\Models\RecordShare;
use App\Models\ReferralLetter;
use App\Services\Patients\RecordShareItems;
use App\Services\Patients\RecordShares;
use App\Services\Referrals\ReferralLetters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La lettre de référence vers un confrère (choix de Jim, 09.11.2026) :
 * créée depuis le dossier avec ses pièces jointes et son destinataire, les
 * notes (motif et question) tapées ou dictées, le brouillon de l'IA, les
 * rubriques corrigées, la signature (droit de signer les comptes rendus :
 * figée, rangée dans les documents de l'animal), puis l'envoi par un lien
 * provisoire (B). Le PDF (A) se compose dans le navigateur. Droit Patients.
 */
class ReferralLetterController extends Controller
{
    /** Les refus dits en français, sous leur champ */
    private const MESSAGES = [
        'recipient_email.email' => 'Cette adresse e-mail n’est pas valable.',
        'recipient_email.max' => 'Cette adresse e-mail est trop longue.',
    ];

    private const REFUSALS = [
        'signed' => 'Cette lettre est signée : elle ne change plus.',
        'writing' => 'Le brouillon est en cours de rédaction.',
        'writings' => 'Le brouillon a déjà été rédigé plusieurs fois : écrivez la lettre vous-même.',
        'budget' => 'L’IA n’est plus disponible ce mois-ci : écrivez la lettre vous-même.',
    ];

    /** Les lettres de l'animal, en bref : la lettre entière se lit une à une */
    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $letters = ReferralLetter::query()->where('patient_id', $patient->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $letters->map(fn (ReferralLetter $l) => [
            'id' => $l->id,
            'is_signed' => $l->isSigned(),
            'recipient_name' => $l->recipient_name,
            'recipient_clinic' => $l->recipient_clinic,
            'signed_at' => $l->signed_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request, Patient $patient, RecordShareItems $items): JsonResponse
    {
        $this->authorize('update', $patient);
        $data = $request->validate([
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.type' => ['required', 'string', Rule::in(RecordShareItems::TYPES)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'visit_notes' => ['sometimes', 'boolean'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'recipient_clinic' => ['sometimes', 'nullable', 'string', 'max:190'],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
        ], self::MESSAGES);
        $employe = app(ContexteClinique::class)->employe();
        $letter = ReferralLetter::query()->create([
            'organisation_id' => $patient->organisation_id,
            'patient_id' => $patient->id,
            'items' => $this->checkedItems($patient, $data['items'] ?? [], $items),
            'include_visit_notes' => (bool) ($data['visit_notes'] ?? false),
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_clinic' => $data['recipient_clinic'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? null,
            'created_by_id' => $employe?->id,
        ]);

        return (new ReferralLetterResource($letter))->response()->setStatusCode(201);
    }

    public function show(ReferralLetter $letter): ReferralLetterResource
    {
        $this->authorize('view', $this->patientOf($letter));

        return new ReferralLetterResource($letter);
    }

    /** Les pièces, le destinataire, les notes, les rubriques relues : tant qu'elle n'est pas signée */
    public function update(Request $request, ReferralLetter $letter, RecordShareItems $items): ReferralLetterResource
    {
        $patient = $this->patientOf($letter);
        $this->authorize('update', $patient);
        $data = $request->validate([
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.type' => ['required', 'string', Rule::in(RecordShareItems::TYPES)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'visit_notes' => ['sometimes', 'boolean'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'recipient_clinic' => ['sometimes', 'nullable', 'string', 'max:190'],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['nullable', 'string', 'max:10000'],
        ], self::MESSAGES);
        $unknown = array_diff(array_keys($data['sections'] ?? []), ReferralLetter::SECTIONS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['sections' => 'Rubrique inconnue : '.implode(', ', $unknown).'.']);
        }
        // Une pièce sortie du dossier depuis (document retiré, compte rendu dévalidé) est retirée sans bloquer le reste
        $chosen = array_key_exists('items', $data) ? $this->checkedItems($patient, $data['items'], $items, isLenient: true) : null;

        $letter = DB::transaction(function () use ($letter, $data, $chosen): ReferralLetter {
            $locked = ReferralLetter::query()->whereKey($letter->id)->lockForUpdate()->firstOrFail();
            if ($locked->isSigned()) {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['signed']]);
            }
            foreach (['recipient_name', 'recipient_clinic', 'recipient_email'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = trim((string) $data[$field]) ?: null;
                }
            }
            // Les pièces ou les notes changées pendant une rédaction (qui a lu ses sources) : un nouveau brouillon suit
            $isSourceChange = false;
            if (array_key_exists('visit_notes', $data)) {
                $isSourceChange = (bool) $data['visit_notes'] !== (bool) $locked->include_visit_notes;
                $locked->include_visit_notes = (bool) $data['visit_notes'];
            }
            if ($chosen !== null) {
                $isSourceChange = $isSourceChange || self::keys($chosen) !== self::keys($locked->items ?? []);
                $locked->items = $chosen;
            }
            if (array_key_exists('notes', $data)) {
                $notes = trim((string) $data['notes']) ?: null;
                $isSourceChange = $isSourceChange || $notes !== $locked->notes;
                $locked->notes = $notes;
            }
            if ($isSourceChange && $locked->status === 'writing') {
                $locked->is_redraft_pending = true;
            }
            if ($isSourceChange) {
                $locked->sources_changed_at = now();
            }
            if (isset($data['sections'])) {
                if ($locked->status === 'writing') {
                    throw ValidationException::withMessages(['letter' => self::REFUSALS['writing']]);
                }
                $edited = $locked->edited_sections ?? [];
                $editedAt = $locked->edited_at ?? [];
                foreach ($data['sections'] as $key => $text) {
                    $lines = $locked->draft['sections'][$key] ?? null;
                    $draftText = implode("\n", array_column(is_array($lines) ? $lines : [], 'text'));
                    if ($locked->draft !== null && trim((string) $text) === $draftText) {
                        $edited = array_values(array_diff($edited, [$key]));
                        unset($editedAt[$key]);
                    } else {
                        $edited = array_values(array_unique([...$edited, $key]));
                        $editedAt[$key] = now()->toIso8601String();
                    }
                }
                $locked->forceFill([
                    'sections' => ReferralLetter::normalizeSections([...ReferralLetter::normalizeSections($locked->sections), ...$data['sections']]),
                    'edited_sections' => $edited,
                    'edited_at' => $editedAt,
                    'status' => in_array($locked->status, ['empty', 'failed'], true) ? 'draft' : $locked->status,
                    'error' => $locked->status === 'failed' ? null : $locked->error,
                ]);
            }
            $locked->save();

            return $locked;
        });
        ReferralLetter::announce($letter->id, $letter->clinique_id);

        return new ReferralLetterResource($letter);
    }

    public function draft(ReferralLetter $letter, ReferralLetters $letters): JsonResponse
    {
        $this->authorize('update', $this->patientOf($letter));
        $refusal = $letters->request($letter);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['letter' => self::REFUSALS[$refusal]]);
        }

        return (new ReferralLetterResource($letter->refresh()))->response()->setStatusCode(202);
    }

    public function sign(Request $request, ReferralLetter $letter, ReferralLetters $letters): ReferralLetterResource
    {
        $this->authorize('update', $this->patientOf($letter));
        Gate::authorize(DomainePermission::SignerComptesRendus->value);

        $letter = DB::transaction(function () use ($letter, $request, $letters): ReferralLetter {
            // Sous le verrou de la lettre, comme une dictée qui commence
            $locked = ReferralLetter::query()->whereKey($letter->id)->lockForUpdate()->firstOrFail();
            $dictating = EnregistrementConsultation::withoutGlobalScopes()->where('referral_letter_id', $locked->id)
                ->whereIn('statut', [StatutEnregistrement::Enregistrement->value, StatutEnregistrement::Transcription->value, StatutEnregistrement::Redaction->value])
                ->exists();
            if ($dictating) {
                throw ValidationException::withMessages(['letter' => 'Une dictée est en cours : terminez-la, ou attendez sa transcription, avant de signer.']);
            }
            if ($locked->isSigned()) {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['signed']]);
            }
            if ($locked->status === 'writing') {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['writing']]);
            }
            if (implode('', ReferralLetter::normalizeSections($locked->sections)) === '') {
                throw ValidationException::withMessages(['letter' => 'La lettre est vide.']);
            }
            $employe = app(ContexteClinique::class)->employe();
            $locked->forceFill([
                'status' => 'signed',
                'error' => null,
                'copied' => $letters->copied($locked),
                'signed_at' => now(),
                'signed_by_id' => $employe?->id,
                'signed_by_name' => $employe->nom ?? $request->user()?->name,
            ])->save();
            // Rangée dans les documents de l'animal
            $locked->forceFill(['document_id' => $letters->file($locked)->id])->save();
            // La dictée est rattachée à la lettre signée
            $closing = EnregistrementConsultation::withoutGlobalScopes()->where('referral_letter_id', $locked->id)
                ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Erreur->value]);
            $recordings = $closing->pluck('id')->all();
            $closing->update(['statut' => StatutEnregistrement::Valide->value, 'valide_le' => now()]);
            foreach ($recordings as $recording) {
                EnregistrementConsultation::annoncer($recording);
            }

            return $locked;
        });
        ReferralLetter::announce($letter->id, $letter->clinique_id);

        return new ReferralLetterResource($letter);
    }

    /** Envoi B : un lien provisoire vers la lettre et ses pièces, envoyé au confrère ou à copier */
    public function link(Request $request, ReferralLetter $letter, RecordShares $shares, RecordShareItems $items): JsonResponse
    {
        $patient = $this->patientOf($letter);
        $this->authorize('update', $patient);
        $data = $request->validate([
            'days' => ['required', 'integer', Rule::in(RecordShare::DURATIONS)],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
        ], self::MESSAGES);
        if (! $letter->isSigned()) {
            throw ValidationException::withMessages(['letter' => 'La lettre part une fois relue et signée.']);
        }
        $email = array_key_exists('recipient_email', $data) ? ($data['recipient_email'] ?: null) : $letter->recipient_email;
        // Les pièces encore au dossier : une pièce sortie depuis la signature n'empêche pas l'envoi
        $attached = $this->checkedItems($patient, $letter->items ?? [], $items, isLenient: true);
        $created = $shares->create($patient, [['type' => 'referral_letter', 'id' => $letter->id], ...$attached], (int) $data['days'], $email, $letter->include_visit_notes, authorName: $request->user()?->name);
        $letter->forceFill(['record_share_id' => $created['share']->id])->save();
        ReferralLetter::announce($letter->id, $letter->clinique_id);

        return response()->json(['data' => [...(new ReferralLetterResource($letter->refresh()))->resolve($request), 'url' => $created['url'], 'is_email_sent' => $created['is_email_sent']]], 201);
    }

    /**
     * Les pièces d'une lettre : seulement de cet animal, partageables
     * aujourd'hui, sans autre lettre de référence
     *
     * @param  array<int, array{type: string, id: int|string}>  $items
     * @return list<array{type: string, id: int}>
     */
    private function checkedItems(Patient $patient, array $items, RecordShareItems $shareItems, bool $isLenient = false): array
    {
        $allowed = collect($shareItems->candidates($patient, null, null))->flatMap(fn (array $g) => array_map(fn (array $i) => $i['type'].':'.$i['id'], $g['items']))->flip();
        $chosen = collect($items)->map(fn (array $i) => ['type' => (string) $i['type'], 'id' => (int) $i['id']])->unique(fn (array $i) => $i['type'].':'.$i['id'])->values();
        if ($isLenient) {
            return $chosen->filter(fn (array $i) => $i['type'] !== 'referral_letter' && $allowed->has($i['type'].':'.$i['id']))->values()->all();
        }
        if ($chosen->contains(fn (array $i) => $i['type'] === 'referral_letter' || ! $allowed->has($i['type'].':'.$i['id']))) {
            throw ValidationException::withMessages(['items' => 'Une pièce n’est pas de ce dossier, ou ne peut pas être jointe.']);
        }

        return $chosen->all();
    }

    /**
     * Les pièces comme ensemble : le même choix dans un autre ordre n'est pas un changement
     *
     * @param  array<int, array{type: string, id: int|string}>  $items
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        $keys = array_map(fn (array $i) => $i['type'].':'.$i['id'], $items);
        sort($keys);

        return $keys;
    }

    private function patientOf(ReferralLetter $letter): Patient
    {
        return $letter->patient ?? abort(404);
    }
}
