<?php

namespace App\Services\Messages;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\PushType;
use App\Events\MessagesChangent;
use App\Models\ClinicMessage;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\StaffMessage;
use App\Services\Notifications\Push;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Messages entre collègues et entre cliniques (décision de Jim, 01.11.2026),
 * dans le même écran que les messages des clients.
 *
 * - « e12 » : la conversation avec le collègue 12, de la même organisation.
 *   À deux : seuls ces deux collègues la lisent, ni l'administrateur ni les
 *   autres.
 * - « k34 » : le canal avec la clinique 34, inscrite et ouverte sur
 *   Veterinary. Toute l'équipe de chacune le lit, comme un groupe ; ouvert
 *   par l'une, il est lu pour elle.
 *
 * Seul le texte part : aucun dossier, aucun document joint. L'annonce en
 * temps réel ne porte aucun contenu (MessagesChangent).
 */
class InternalMessaging
{
    public function __construct(private ContexteClinique $contexte, private CalculateurPermissions $permissions) {}

    /** @return list<array<string, mixed>> */
    public function threads(): array
    {
        return [...$this->staffThreads(), ...$this->clinicThreads()];
    }

    /** @return array<string, mixed> */
    public function thread(string $key): array
    {
        if ($key[0] === 'e') {
            $colleague = $this->colleague((int) substr($key, 1));
            $me = $this->me();
            $this->staffBetween($me, $colleague)->where('recipient_employee_id', $me->id)->whereNull('read_at')->update(['read_at' => now()]);
            $messages = $this->staffBetween($me, $colleague)->with('sender')->orderBy('created_at')->orderBy('id')->get();

            return [
                ...$this->staffHeader($colleague),
                'messages' => $messages->map(fn (StaffMessage $m): array => $this->staffMessage($m, $me))->all(),
            ];
        }

        $other = $this->otherClinic((int) substr($key, 1));
        $mine = $this->clinic();
        $this->clinicBetween($mine, $other)->where('recipient_clinic_id', $mine->id)->whereNull('read_at')->update(['read_at' => now()]);
        $messages = $this->clinicBetween($mine, $other)->with('author')->orderBy('created_at')->orderBy('id')->get();

        return [
            ...$this->clinicHeader($other),
            'messages' => $messages->map(fn (ClinicMessage $m): array => $this->clinicMessage($m, $mine, $other))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function send(string $key, string $body): array
    {
        $me = $this->me();
        if ($key[0] === 'e') {
            $colleague = $this->colleague((int) substr($key, 1));
            if (! $this->canWriteTo($colleague)) {
                throw ValidationException::withMessages(['texte' => $colleague->nom.' ne peut plus lire les Messages : écrivez-lui autrement.']);
            }
            $message = StaffMessage::create([
                'organisation_id' => $me->organisation_id,
                'sender_employee_id' => $me->id,
                'recipient_employee_id' => $colleague->id,
                'body' => $body,
            ]);
            foreach ([$me->user_id, $colleague->user_id] as $userId) {
                TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId));
            }
            Push::to([$colleague->user_id], PushType::TeamMessage, $me->nom, mb_strimwidth($body, 0, 120, '…'), '/clinique/messages');

            return $this->staffMessage($message->load('sender'), $me);
        }

        $other = $this->otherClinic((int) substr($key, 1));
        if (! Clinique::withoutGlobalScopes()->ouvertes()->whereKey($other->id)->exists()) {
            throw ValidationException::withMessages(['texte' => $other->nom.' n’est plus ouverte sur Veterinary : appelez-la.']);
        }
        $mine = $this->clinic();
        $message = ClinicMessage::create([
            'sender_clinic_id' => $mine->id,
            'recipient_clinic_id' => $other->id,
            'author_employee_id' => $me->id,
            'body' => $body,
        ]);
        foreach ([$mine->id, $other->id] as $cliniqueId) {
            TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$cliniqueId));
        }
        // Toute l'équipe de l'autre clinique qui lit les Messages (droit patients, comme pour y écrire)
        Push::to(Push::staffOf($other->id, DomainePermission::Patients), PushType::TeamMessage, $mine->nom, mb_strimwidth($body, 0, 120, '…'), '/clinique/messages');

        return $this->clinicMessage($message->load('author'), $mine, $other);
    }

    /**
     * « À : » : les collègues de l'organisation qui lisent les Messages, et
     * les cliniques ouvertes sur Veterinary, par nom (et ville pour une
     * clinique).
     *
     * @return array{staff: list<array<string, mixed>>, clinics: list<array<string, mixed>>}
     */
    public function contacts(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['staff' => [], 'clinics' => []];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        $me = $this->me();

        $staff = Employe::withoutGlobalScopes()->where('organisation_id', $me->organisation_id)->whereKeyNot($me->id)
            ->where('nom', 'like', $like)->with(['role', 'derogations', 'cliniques'])->orderBy('nom')->limit(20)->get()
            ->filter(fn (Employe $e): bool => $this->canWriteTo($e))->take(8)
            ->map(fn (Employe $e): array => [
                'cle' => 'e'.$e->id,
                'name' => $e->nom,
                'subtitle' => implode(', ', array_filter([$e->role_libelle, $e->cliniques->pluck('nom')->implode(', ')])),
            ])->values()->all();

        $clinics = Clinique::withoutGlobalScopes()->ouvertes()->whereKeyNot($this->clinic()->id)
            ->where(fn (Builder $q) => $q->where('nom', 'like', $like)->orWhere('ville', 'like', $like))
            ->orderBy('nom')->limit(8)->get()
            ->map(fn (Clinique $c): array => ['cle' => 'k'.$c->id, 'name' => $c->nom, 'subtitle' => $c->ville])->all();

        return ['staff' => $staff, 'clinics' => $clinics];
    }

    /** @return list<array<string, mixed>> */
    private function staffThreads(): array
    {
        $me = $this->me();
        $messages = StaffMessage::query()->where(fn (Builder $q) => $q->where('sender_employee_id', $me->id)->orWhere('recipient_employee_id', $me->id))
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (StaffMessage $m): int => $m->sender_employee_id === $me->id ? $m->recipient_employee_id : $m->sender_employee_id);
        $colleagues = Employe::withoutGlobalScopes()->where('organisation_id', $me->organisation_id)->whereKey($messages->keys())
            ->with(['role', 'derogations', 'cliniques'])->get()->keyBy('id');

        return $messages->map(function (Collection $thread, int $colleagueId) use ($colleagues, $me): ?array {
            $colleague = $colleagues[$colleagueId] ?? null;
            if ($colleague === null) {
                return null;
            }
            /** @var StaffMessage $last */
            $last = $thread->first();

            return [
                ...$this->staffHeader($colleague),
                'non_lus' => $thread->where('recipient_employee_id', $me->id)->whereNull('read_at')->count(),
                'dernier' => ['texte' => mb_strimwidth($last->body, 0, 140, '…'), 'is_mine' => $last->sender_employee_id === $me->id, 'envoye_le' => $last->created_at?->toIso8601String()],
            ];
        })->filter()->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function clinicThreads(): array
    {
        $mine = $this->clinic();
        $messages = ClinicMessage::query()->where(fn (Builder $q) => $q->where('sender_clinic_id', $mine->id)->orWhere('recipient_clinic_id', $mine->id))
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (ClinicMessage $m): int => $m->sender_clinic_id === $mine->id ? $m->recipient_clinic_id : $m->sender_clinic_id);
        $clinics = Clinique::withoutGlobalScopes()->whereKey($messages->keys())->get()->keyBy('id');
        $open = Clinique::withoutGlobalScopes()->ouvertes()->whereKey($messages->keys())->pluck('id')->all();

        return $messages->map(function (Collection $thread, int $clinicId) use ($clinics, $mine, $open): ?array {
            $other = $clinics[$clinicId] ?? null;
            if ($other === null) {
                return null;
            }
            /** @var ClinicMessage $last */
            $last = $thread->first();

            return [
                ...$this->clinicHeader($other, in_array($other->id, $open, true)),
                'non_lus' => $thread->where('recipient_clinic_id', $mine->id)->whereNull('read_at')->count(),
                'dernier' => ['texte' => mb_strimwidth($last->body, 0, 140, '…'), 'is_mine' => $last->sender_clinic_id === $mine->id, 'envoye_le' => $last->created_at?->toIso8601String()],
            ];
        })->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    private function staffHeader(Employe $colleague): array
    {
        return ['cle' => 'e'.$colleague->id, 'kind' => 'staff', 'name' => $colleague->nom, 'subtitle' => 'Collègue', 'user_id' => null, 'peut_repondre' => $this->canWriteTo($colleague)];
    }

    /** @return array<string, mixed> */
    private function clinicHeader(Clinique $other, ?bool $isOpen = null): array
    {
        return [
            'cle' => 'k'.$other->id, 'kind' => 'clinic', 'name' => $other->nom, 'subtitle' => $other->ville, 'user_id' => null,
            'peut_repondre' => $isOpen ?? Clinique::withoutGlobalScopes()->ouvertes()->whereKey($other->id)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function staffMessage(StaffMessage $m, Employe $me): array
    {
        return [
            'id' => 'e'.$m->id,
            'is_mine' => $m->sender_employee_id === $me->id,
            'signature' => $m->sender?->nom,
            'texte' => $m->body,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->read_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function clinicMessage(ClinicMessage $m, Clinique $mine, Clinique $other): array
    {
        $clinic = $m->sender_clinic_id === $mine->id ? $mine : $other;

        return [
            'id' => 'k'.$m->id,
            'is_mine' => $m->sender_clinic_id === $mine->id,
            'signature' => implode(', ', array_filter([$m->author?->nom, $clinic->nom])),
            'texte' => $m->body,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->read_at?->toIso8601String(),
        ];
    }

    /** Peut lire les Messages : accès actif, contrat en cours, affecté à une clinique, le droit Patients */
    private function canWriteTo(Employe $employe): bool
    {
        // Les affectations sont chargées avec la fiche (liste, « À : », conversation) : aucune requête par collègue
        return $employe->estPresentable() && $employe->cliniques->isNotEmpty()
            && in_array(DomainePermission::Patients->value, $this->permissions->pour($employe), true);
    }

    /** @return Builder<StaffMessage> */
    private function staffBetween(Employe $me, Employe $colleague): Builder
    {
        return StaffMessage::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $a) => $a->where('sender_employee_id', $me->id)->where('recipient_employee_id', $colleague->id))
            ->orWhere(fn (Builder $b) => $b->where('sender_employee_id', $colleague->id)->where('recipient_employee_id', $me->id)));
    }

    /** @return Builder<ClinicMessage> */
    private function clinicBetween(Clinique $mine, Clinique $other): Builder
    {
        return ClinicMessage::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $a) => $a->where('sender_clinic_id', $mine->id)->where('recipient_clinic_id', $other->id))
            ->orWhere(fn (Builder $b) => $b->where('sender_clinic_id', $other->id)->where('recipient_clinic_id', $mine->id)));
    }

    /** Un collègue de la même organisation, jamais soi-même ; sinon, cette conversation n'existe pas ici */
    private function colleague(int $id): Employe
    {
        $me = $this->me();
        if ($id === $me->id) {
            throw (new ModelNotFoundException)->setModel(Employe::class, [$id]);
        }

        return Employe::withoutGlobalScopes()->where('organisation_id', $me->organisation_id)->with(['role', 'derogations', 'cliniques'])->findOrFail($id);
    }

    /** Une autre clinique : ouverte, ou déjà dans une conversation avec la mienne */
    private function otherClinic(int $id): Clinique
    {
        $mine = $this->clinic();
        if ($id === $mine->id) {
            throw (new ModelNotFoundException)->setModel(Clinique::class, [$id]);
        }
        $other = Clinique::withoutGlobalScopes()->findOrFail($id);
        $known = Clinique::withoutGlobalScopes()->ouvertes()->whereKey($id)->exists() || $this->clinicBetween($mine, $other)->exists();
        if (! $known) {
            throw (new ModelNotFoundException)->setModel(Clinique::class, [$id]);
        }

        return $other;
    }

    private function me(): Employe
    {
        $me = $this->contexte->employe();
        if ($me === null) {
            throw (new ModelNotFoundException)->setModel(Employe::class);
        }

        return $me;
    }

    private function clinic(): Clinique
    {
        $clinic = $this->contexte->clinique();
        if ($clinic === null) {
            throw (new ModelNotFoundException)->setModel(Clinique::class);
        }

        return $clinic;
    }
}
