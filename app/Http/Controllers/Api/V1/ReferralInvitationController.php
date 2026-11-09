<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\ReferralInvitation;
use App\Models\ReferralLetter;
use App\Services\Patients\RecordShareItems;
use App\Services\Referrals\ReferralInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La lettre de référence envoyée dans Veterinary, envoi C (choix de Jim,
 * 09.11.2026). Côté clinique qui réfère : l'annuaire, l'envoi à un
 * vétérinaire ou à une clinique, couper l'accès. Côté destinataire : ses
 * invitations (avant d'accepter : qui réfère et l'animal, rien de la
 * lettre), accepter en choisissant ou créant l'animal chez lui, refuser ;
 * puis, depuis le dossier de cet animal, la lettre et ses pièces telles
 * qu'à la signature, sans copie, tant que l'accès n'est pas coupé.
 */
class ReferralInvitationController extends Controller
{
    public function __construct(private ReferralInvitations $invitations) {}

    public function directory(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return response()->json(['data' => $this->invitations->directory($q, app(ContexteClinique::class)->organisationId())]);
    }

    public function store(Request $request, ReferralLetter $letter): JsonResponse
    {
        $this->authorize('update', Patient::withoutGlobalScopes()->where('organisation_id', $letter->organisation_id)->findOrFail($letter->patient_id));
        $data = $request->validate([
            'kind' => ['required', Rule::in(['vet', 'clinic'])],
            'user_id' => ['nullable', 'integer', 'required_without_all:email,clinique_id'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'clinique_id' => ['nullable', 'integer', 'required_if:kind,clinic'],
        ], ['email.email' => 'Cette adresse e-mail n’est pas valable.']);
        $invitation = $this->invitations->send($letter, $data, app(ContexteClinique::class)->employe()->nom ?? $request->user()?->name);

        return response()->json(['data' => self::forSender($invitation)], 201);
    }

    /** Couper : depuis toute clinique de l'organisation qui réfère */
    public function revoke(Request $request, int $invitation): JsonResponse
    {
        $found = ReferralInvitation::query()->where('organisation_id', app(ContexteClinique::class)->organisationId())->findOrFail($invitation);
        $this->authorize('update', $found->patient ?? abort(404));

        return response()->json(['data' => self::forSender($this->invitations->revoke($found, app(ContexteClinique::class)->employe()->nom ?? $request->user()?->name))]);
    }

    /** Mes invitations en attente, ou acceptées sans animal encore choisi : à moi, ou à la clinique où je travaille maintenant */
    public function index(Request $request): JsonResponse
    {
        $context = app(ContexteClinique::class);
        $list = ReferralInvitation::query()->forRecipient((int) $request->user()?->id, $context->cliniqueId())
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($a) => $a->where('status', 'accepted')->whereNull('target_patient_id')->where('target_clinique_id', $context->cliniqueId())))
            ->latest('id')->limit(50)->get()
            ->filter(fn (ReferralInvitation $i) => $this->invitations->canAnswer($i, $request->user(), $context->cliniqueId()))
            ->map(fn (ReferralInvitation $i) => $this->forRecipient($i))->values();

        return response()->json(['data' => $list]);
    }

    /** Une invitation : avant d'accepter, de qui et l'animal ; acceptée, de quoi choisir ou créer l'animal */
    public function show(Request $request, int $invitation): JsonResponse
    {
        $found = $this->answerable($request, $invitation);

        return response()->json(['data' => $this->withPrefill($found)]);
    }

    public function open(Request $request, int $invitation): JsonResponse
    {
        $found = $this->answerable($request, $invitation);
        // Lue : seulement une invitation encore en attente
        if ($found->opened_at === null && $found->isPending()) {
            $found->forceFill(['opened_at' => now()])->save();
            $found->announce();
        }

        return response()->json(['data' => $this->forRecipient($found)]);
    }

    /** Accepter, depuis la clinique où il travaille : rend de quoi choisir ou créer l'animal */
    public function accept(Request $request, int $invitation): JsonResponse
    {
        $found = $this->answerable($request, $invitation);
        $this->authorize('create', Patient::class);
        $context = app(ContexteClinique::class);

        return response()->json(['data' => $this->withPrefill($this->invitations->accept($found, (int) $context->cliniqueId(), $request->user(), $context->employe()?->nom))]);
    }

    /** L'animal choisi, ou créé par le formulaire habituel : il devient un patient référé */
    public function link(Request $request, int $invitation): JsonResponse
    {
        $found = $this->answerable($request, $invitation);
        abort_unless($found->target_clinique_id === app(ContexteClinique::class)->cliniqueId(), 404);
        $data = $request->validate(['patient_id' => ['required', 'integer']]);
        // L'animal de son organisation seulement (portée globale du contexte)
        $patient = Patient::query()->findOrFail($data['patient_id']);
        $this->authorize('update', $patient);

        return response()->json(['data' => $this->forRecipient($this->invitations->link($found, $patient))]);
    }

    /** @return array<string, mixed> */
    private function withPrefill(ReferralInvitation $i): array
    {
        return [...$this->forRecipient($i), 'prefill' => $i->status === 'accepted' && $i->target_patient_id === null ? $this->prefill($i) : null];
    }

    public function refuse(Request $request, int $invitation): JsonResponse
    {
        $found = $this->answerable($request, $invitation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $refused = $this->invitations->refuse($found, $data['reason'] ?? null, app(ContexteClinique::class)->employe()->nom ?? $request->user()?->name);

        return response()->json(['data' => $this->forRecipient($refused)]);
    }

    /** Les lettres reçues pour cet animal (référé), ouvertes ou coupées */
    public function forPatient(Patient $patient, RecordShareItems $items): JsonResponse
    {
        $this->authorize('view', $patient);
        $list = ReferralInvitation::query()->where('target_patient_id', $patient->id)->whereIn('status', ['accepted', 'revoked'])
            ->whereNotNull('accepted_at')->latest('accepted_at')->get()
            ->map(fn (ReferralInvitation $i) => [...$this->forRecipient($i), 'items' => $i->isOpenToTarget() ? $this->listOf($i, $items) : []])->values();

        return response()->json(['data' => $list]);
    }

    /** Une pièce, telle qu'à la signature de la lettre */
    public function item(Request $request, int $invitation, string $type, int $id, RecordShareItems $items): JsonResponse
    {
        $found = $this->readable($invitation, $type, $id);
        $letter = $found->letter ?? abort(404);

        return response()->json(['data' => $items->printable($found->patient ?? abort(404), $type, $id, ['visit_notes' => $letter->include_visit_notes, 'as_of' => $letter->signed_at]) ?? abort(404)]);
    }

    public function file(int $invitation, string $type, int $id, int $n, RecordShareItems $items): StreamedResponse
    {
        $found = $this->readable($invitation, $type, $id);
        $file = $items->file($found->patient ?? abort(404), $type, $id, $n) ?? abort(404);

        return Storage::disk('prive')->download($file['path'], $file['name'], ['Content-Type' => $file['mime']]);
    }

    /** Une invitation en attente à laquelle ce compte peut répondre d'ici ; sinon introuvable (rien n'est dit) */
    private function answerable(Request $request, int $id): ReferralInvitation
    {
        $context = app(ContexteClinique::class);
        $found = ReferralInvitation::query()->forRecipient((int) $request->user()?->id, $context->cliniqueId())->findOrFail($id);
        abort_unless($this->invitations->canAnswer($found, $request->user(), $context->cliniqueId()), 404);
        $this->authorize('viewAny', Patient::class);

        return $found;
    }

    /** Acceptée, pas coupée, pour un animal de mon organisation, et une pièce de la lettre */
    private function readable(int $id, string $type, int $itemId): ReferralInvitation
    {
        $found = ReferralInvitation::query()->where('status', 'accepted')->findOrFail($id);
        // L'animal référé de mon organisation (portée globale du contexte)
        $target = Patient::query()->findOrFail($found->target_patient_id);
        $this->authorize('view', $target);
        $letter = $found->letter ?? abort(404);
        $allowed = collect([['type' => 'referral_letter', 'id' => $letter->id], ...($letter->items ?? [])])->contains(fn (array $i) => $i['type'] === $type && (int) $i['id'] === $itemId);
        abort_unless($allowed, 404);

        return $found;
    }

    /**
     * La lettre en tête, puis ses pièces, telles qu'à la signature
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(ReferralInvitation $invitation, RecordShareItems $items): array
    {
        $letter = $invitation->letter;
        $patient = $invitation->patient;
        if (! $letter || ! $patient) {
            return [];
        }
        $chosen = collect([['type' => 'referral_letter', 'id' => $letter->id], ...($letter->items ?? [])])->map(fn (array $i) => $i['type'].':'.$i['id'])->flip();

        return collect($items->candidates($patient, null, null, $letter->signed_at))
            ->flatMap(fn (array $g) => $g['items'])
            ->filter(fn (array $i) => $chosen->has($i['type'].':'.$i['id']))
            ->sortByDesc(fn (array $i) => ($i['type'] === 'referral_letter' ? '1' : '0').$i['date'].sprintf('%010d', $i['id']))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function forRecipient(ReferralInvitation $i): array
    {
        $letter = $i->letter;
        $patient = $i->patient;
        $from = Clinique::withoutGlobalScopes()->find($i->clinique_id);

        return [
            'id' => $i->id,
            'status' => $i->status,
            'recipient_kind' => $i->recipient_kind,
            'from' => ['clinic' => $from?->nom, 'city' => $from?->ville, 'phone' => $from?->tel, 'by' => $i->sent_by_name],
            'animal' => ['name' => $patient?->nom, 'species' => $patient?->espece->libelle()],
            'sent_at' => $i->created_at?->toIso8601String(),
            'signed_at' => $letter?->signed_at?->toIso8601String(),
            'accepted_at' => $i->accepted_at?->toIso8601String(),
            'target_patient_id' => $i->target_patient_id,
            'revoked_at' => $i->revoked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forSender(ReferralInvitation $i): array
    {
        return [
            'id' => $i->id,
            'recipient_kind' => $i->recipient_kind,
            'recipient_name' => $i->recipient_name,
            'status' => $i->status,
            'sent_at' => $i->created_at?->toIso8601String(),
            'sent_by_name' => $i->sent_by_name,
            'opened_at' => $i->opened_at?->toIso8601String(),
            'accepted_at' => $i->accepted_at?->toIso8601String(),
            'accepted_by_name' => $i->accepted_by_name,
            'target_clinic' => $i->targetClinique?->nom,
            'refused_at' => $i->refused_at?->toIso8601String(),
            'refused_by_name' => $i->refused_by_name,
            'refusal_reason' => $i->refusal_reason,
            'revoked_at' => $i->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * De quoi préremplir l'animal et son propriétaire, s'il faut les créer
     *
     * @return array<string, mixed>
     */
    private function prefill(ReferralInvitation $i): array
    {
        $patient = Patient::withoutGlobalScopes()->find($i->patient_id);
        if (! $patient) {
            return [];
        }
        // Le propriétaire chez la clinique qui réfère (hors de l'organisation du contexte)
        $owner = Client::withoutGlobalScopes()->find($patient->client_id);

        return [
            'patient' => [
                'name' => $patient->nom, 'species' => $patient->espece->value, 'breed' => $patient->race, 'sex' => $patient->sexe->value,
                'is_sterilized' => (bool) $patient->est_sterilise, 'born_on' => $patient->ne_le?->toDateString(), 'chip' => $patient->puce,
            ],
            'owner' => $owner ? ['first_name' => $owner->prenom === '—' ? null : $owner->prenom, 'last_name' => $owner->nom, 'phone' => $owner->tel, 'email' => $owner->email] : null,
            // Un animal déjà chez lui, à proposer d'abord : même puce, ou même nom et même propriétaire
            'matches' => $this->matches($patient),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function matches(Patient $source): array
    {
        $query = Patient::query()->with('client')->limit(5);
        $query->where(function ($q) use ($source): void {
            if ($source->puce) {
                $q->orWhere('puce', $source->puce);
            }
            $ownerName = Client::withoutGlobalScopes()->whereKey($source->client_id)->value('nom') ?? '';
            $q->orWhere(fn ($n) => $n->where('nom', $source->nom)->whereHas('client', fn ($c) => $c->where('nom', $ownerName)));
        });

        return $query->get()->map(fn (Patient $p) => ['id' => $p->id, 'name' => $p->nom, 'species' => $p->espece->libelle(), 'owner' => $p->client?->fullName(), 'chip' => $p->puce])->values()->all();
    }
}
