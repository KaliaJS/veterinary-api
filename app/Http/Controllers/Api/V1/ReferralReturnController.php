<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\ReferralInvitation;
use App\Models\ReferralReturn;
use App\Services\Patients\RecordShareItems;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le retour au vétérinaire référent (09.11.2026). Côté confrère, depuis le
 * dossier de l'animal référé : ses pièces signées (celles depuis
 * l'acceptation, cochées d'office), un mot, « Envoyer au vétérinaire
 * référent ». Côté clinique qui a référé : les retours reçus, en tête de
 * l'onglet Documents de son animal, lus sans copie, tels qu'à l'envoi.
 */
class ReferralReturnController extends Controller
{
    /** Les pièces signées de l'animal référé, et celles depuis l'acceptation (cochées d'office) */
    public function candidates(int $invitation, RecordShareItems $items): JsonResponse
    {
        [$found, $target] = $this->mine($invitation);
        $since = $this->localDay($found);

        return response()->json(['data' => [
            'groups' => collect($items->candidates($target, null, null))->filter(fn (array $g) => $g['key'] !== 'referral_letter')->values(),
            'since' => $since,
        ]]);
    }

    /** Les retours déjà envoyés pour cette lettre reçue */
    public function sent(int $invitation): JsonResponse
    {
        // Ses retours restent les siens, même après la coupure de la lettre : il les voit et peut les couper
        [$found] = $this->mine($invitation, isRevokedToo: true);

        return response()->json(['data' => $this->returnsOf($found)]);
    }

    public function store(Request $request, int $invitation, RecordShareItems $items): JsonResponse
    {
        [$found, $target] = $this->mine($invitation);
        $this->authorize('update', $target);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.type' => ['required', 'string', Rule::in(RecordShareItems::TYPES)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $allowed = collect($items->candidates($target, null, null))->flatMap(fn (array $g) => array_map(fn (array $i) => $i['type'].':'.$i['id'], $g['items']))->flip();
        $chosen = collect($data['items'])->map(fn (array $i) => ['type' => (string) $i['type'], 'id' => (int) $i['id']])->unique(fn (array $i) => $i['type'].':'.$i['id'])->values();
        if ($chosen->contains(fn (array $i) => $i['type'] === 'referral_letter' || ! $allowed->has($i['type'].':'.$i['id']))) {
            throw ValidationException::withMessages(['items' => 'Une pièce n’est pas de ce dossier, ou ne peut pas être envoyée.']);
        }
        $context = app(ContexteClinique::class);
        ReferralReturn::query()->create([
            'referral_invitation_id' => $found->id,
            'clinique_id' => $context->cliniqueId(),
            'patient_id' => $target->id,
            'items' => $chosen->all(),
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'sent_by_name' => $context->employe()->nom ?? $request->user()?->name,
        ]);
        // La clinique qui a référé relit le dossier de son animal (Reverb)
        $found->announce();

        return response()->json(['data' => $this->returnsOf($found)], 201);
    }

    /** Côté clinique qui a référé : les retours reçus pour cet animal */
    public function forPatient(Patient $patient, RecordShareItems $items): JsonResponse
    {
        $this->authorize('view', $patient);
        $invitations = ReferralInvitation::query()->where('organisation_id', $patient->organisation_id)->where('patient_id', $patient->id)->pluck('id');
        $returns = ReferralReturn::query()->whereIn('referral_invitation_id', $invitations)->whereNull('revoked_at')->latest('id')->limit(50)->get();

        return response()->json(['data' => $returns->map(fn (ReferralReturn $r) => [...$this->present($r), 'items' => $this->listOf($r, $items)])->values()]);
    }

    /** Côté confrère : couper un retour, ses pièces ne se lisent plus chez la clinique qui a référé */
    public function revoke(Request $request, int $invitation, int $return): JsonResponse
    {
        [$found] = $this->mine($invitation, isRevokedToo: true);
        $this->authorize('update', Patient::query()->findOrFail($found->target_patient_id));
        $sent = ReferralReturn::query()->where('referral_invitation_id', $found->id)->findOrFail($return);
        if ($sent->revoked_at === null) {
            $sent->forceFill(['revoked_at' => now(), 'revoked_by_name' => app(ContexteClinique::class)->employe()->nom ?? $request->user()?->name])->save();
            $found->announce();
        }

        return response()->json(['data' => $this->returnsOf($found)]);
    }

    public function open(int $return): JsonResponse
    {
        $found = $this->received($return);
        if ($found->opened_at === null) {
            $found->forceFill(['opened_at' => now()])->save();
            $found->invitation?->announce();
        }

        return response()->json(['data' => $this->present($found)]);
    }

    /** Une pièce renvoyée, telle qu'à l'envoi */
    public function item(int $return, string $type, int $id, RecordShareItems $items): JsonResponse
    {
        $found = $this->readable($return, $type, $id);

        return response()->json(['data' => $items->printable($found->patient ?? abort(404), $type, $id, ['as_of' => $found->created_at]) ?? abort(404)]);
    }

    public function file(int $return, string $type, int $id, int $n, RecordShareItems $items): StreamedResponse
    {
        $found = $this->readable($return, $type, $id);
        $file = $items->file($found->patient ?? abort(404), $type, $id, $n) ?? abort(404);

        return Storage::disk('prive')->download($file['path'], $file['name'], ['Content-Type' => $file['mime']]);
    }

    /**
     * Une lettre reçue, acceptée et liée à un animal de mon organisation, depuis la clinique qui l'a acceptée
     *
     * @return array{0: ReferralInvitation, 1: Patient}
     */
    private function mine(int $id, bool $isRevokedToo = false): array
    {
        $found = ReferralInvitation::query()->whereIn('status', $isRevokedToo ? ['accepted', 'revoked'] : ['accepted'])->whereNotNull('target_patient_id')->findOrFail($id);
        // L'animal référé de mon organisation (portée globale du contexte) : depuis toute clinique de l'organisation qui l'a accepté
        $target = Patient::query()->findOrFail($found->target_patient_id);
        $this->authorize('view', $target);

        return [$found, $target];
    }

    /** Un retour reçu par mon organisation (celle qui a référé), pas coupé par le confrère */
    private function received(int $id): ReferralReturn
    {
        $found = ReferralReturn::query()->whereNull('revoked_at')->with('invitation')->findOrFail($id);
        $invitation = $found->invitation ?? abort(404);
        abort_unless($invitation->organisation_id === app(ContexteClinique::class)->organisationId(), 404);
        $this->authorize('view', Patient::query()->findOrFail($invitation->patient_id));

        return $found;
    }

    private function readable(int $id, string $type, int $itemId): ReferralReturn
    {
        $found = $this->received($id);
        abort_unless(collect($found->items ?? [])->contains(fn (array $i) => $i['type'] === $type && (int) $i['id'] === $itemId), 404);

        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function returnsOf(ReferralInvitation $invitation): array
    {
        return ReferralReturn::query()->where('referral_invitation_id', $invitation->id)->latest('id')->get()
            ->map(fn (ReferralReturn $r) => [...$this->present($r), 'items_count' => count($r->items ?? [])])->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(ReferralReturn $r): array
    {
        $from = Clinique::withoutGlobalScopes()->find($r->clinique_id);

        return [
            'id' => $r->id,
            'invitation_id' => $r->referral_invitation_id,
            'from' => ['clinic' => $from?->nom, 'city' => $from?->ville, 'phone' => $from?->tel, 'by' => $r->sent_by_name],
            'note' => $r->note,
            'sent_at' => $r->created_at?->toIso8601String(),
            'opened_at' => $r->opened_at?->toIso8601String(),
            'revoked_at' => $r->revoked_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listOf(ReferralReturn $r, RecordShareItems $items): array
    {
        $patient = $r->patient;
        if (! $patient) {
            return [];
        }
        $chosen = collect($r->items ?? [])->map(fn (array $i) => $i['type'].':'.$i['id'])->flip();

        return collect($items->candidates($patient, null, null, $r->created_at))
            ->flatMap(fn (array $g) => $g['items'])
            ->filter(fn (array $i) => $chosen->has($i['type'].':'.$i['id']))
            ->sortByDesc(fn (array $i) => $i['date'].sprintf('%010d', $i['id']))
            ->values()->all();
    }

    /** Le jour de l'acceptation, à l'heure de la clinique qui a accepté */
    private function localDay(ReferralInvitation $invitation): ?string
    {
        if (! $invitation->accepted_at) {
            return null;
        }
        $zone = Clinique::withoutGlobalScopes()->find($invitation->target_clinique_id)?->fuseau_horaire ?: 'Europe/Zurich';

        return CarbonImmutable::parse($invitation->accepted_at)->setTimezone($zone)->toDateString();
    }
}
