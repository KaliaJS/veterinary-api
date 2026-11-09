<?php

namespace App\Services\Referrals;

use App\Auth\EmetteurJetons;
use App\Auth\Rattachement;
use App\Mail\ReferralInvitationMail;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\ReferralInvitation;
use App\Models\ReferralLetter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La lettre de référence envoyée dans Veterinary, envoi C (choix de Jim,
 * 09.11.2026). Le confrère se choisit dans l'annuaire (les cliniques
 * ouvertes, et leurs vétérinaires quand la clinique les affiche) ou par son
 * e-mail ; celui qui envoie invite le vétérinaire seul ou toute sa clinique.
 * L'invitation arrive dans ses messages et par e-mail (rien de médical dans
 * l'e-mail). Acceptée, l'animal choisi ou créé chez lui consulte la lettre
 * et ses pièces, sans copie, jusqu'à ce que la clinique qui réfère coupe
 * l'accès. Refusée, rien n'est partagé, et la clinique qui réfère le voit.
 */
final class ReferralInvitations
{
    public function __construct(private EmetteurJetons $jetons) {}

    /**
     * L'annuaire : les cliniques ouvertes par nom ou par ville, et leurs
     * vétérinaires quand la clinique les affiche
     *
     * @return list<array<string, mixed>>
     */
    public function directory(string $query, ?int $exceptOrganisationId = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        // Par le nom d'un vétérinaire : seulement dans une clinique qui montre ses vétérinaires (rien n'est dit des autres)
        $byName = $this->vets()->where('nom', 'like', $like)
            ->whereHas('cliniques', fn (Builder $c) => $c->withoutGlobalScopes()->where('affiche_veterinaires', true))
            ->when($exceptOrganisationId, fn (Builder $q) => $q->where('organisation_id', '!=', $exceptOrganisationId))
            ->with('cliniques')->limit(20)->get();
        // Ses propres cliniques n'y sont pas : le dossier y est déjà commun
        $clinics = Clinique::withoutGlobalScopes()->ouvertes()
            ->when($exceptOrganisationId, fn (Builder $q) => $q->whereHas('societe', fn (Builder $s) => $s->withoutGlobalScopes()->where('organisation_id', '!=', $exceptOrganisationId)))
            ->where(fn (Builder $q) => $q->where('nom', 'like', $like)->orWhere('ville', 'like', $like)
                ->orWhere(fn (Builder $v) => $v->where('affiche_veterinaires', true)->whereIn('id', $byName->flatMap(fn (Employe $e) => $e->cliniques->pluck('id'))->unique())))
            ->orderBy('nom')->limit(12)->get();

        return $clinics->map(fn (Clinique $c): array => [
            'id' => $c->id,
            'name' => $c->nom,
            'city' => $c->ville,
            'vets' => $c->affiche_veterinaires ? $this->vetsOf($c)->map(fn (Employe $e): array => ['user_id' => $e->user_id, 'name' => $e->nom])->values()->all() : [],
        ])->values()->all();
    }

    /** @return Builder<Employe> */
    private function vets(): Builder
    {
        return Employe::withoutGlobalScopes()->whereNotNull('user_id')->where('acces_suspendu', false)
            ->where('role_libelle', 'like', 'Vétérinaire%')
            ->where(fn (Builder $q) => $q->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', now()->toDateString()));
    }

    /** @return Collection<int, Employe> */
    private function vetsOf(Clinique $clinique): Collection
    {
        return $this->vets()->whereHas('cliniques', fn (Builder $q) => $q->whereKey($clinique->id))
            ->where('organisation_id', $clinique->societe?->organisation_id)->orderBy('nom')->limit(30)->get();
    }

    /** Le compte d'un vétérinaire qui travaille dans une clinique ouverte, pour cette adresse */
    public function vetByEmail(string $email): ?User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $user && $this->openVetClinics($user)->isNotEmpty() ? $user : null;
    }

    /**
     * Les cliniques ouvertes (vérifiées, abonnées) où ce compte travaille comme vétérinaire
     *
     * @return Collection<int, Rattachement>
     */
    private function openVetClinics(User $user): Collection
    {
        $vetClinics = $this->vetClinics($user);
        $open = Clinique::withoutGlobalScopes()->ouvertes()->whereIn('id', $vetClinics->map(fn (Rattachement $r) => $r->clinique->id))->pluck('id')->flip();

        return $vetClinics->filter(fn (Rattachement $r): bool => $open->has($r->clinique->id))->values();
    }

    /**
     * Les cliniques où ce compte travaille comme vétérinaire
     *
     * @return Collection<int, Rattachement>
     */
    public function vetClinics(User $user): Collection
    {
        return $this->jetons->rattachements($user)->filter(fn (Rattachement $r): bool => $r->employe->estVeterinaire())->values();
    }

    /**
     * Envoyer la lettre signée : à un vétérinaire (`user_id` de l'annuaire,
     * ou `email`) ou à une clinique (`clinique_id`). Une invitation encore
     * en attente ou acceptée pour le même destinataire n'est pas doublée.
     *
     * @param  array{kind: string, user_id?: int|null, email?: string|null, clinique_id?: int|null}  $to
     */
    public function send(ReferralLetter $letter, array $to, ?string $sentBy): ReferralInvitation
    {
        if (! $letter->isSigned()) {
            throw ValidationException::withMessages(['letter' => 'La lettre part une fois relue et signée.']);
        }
        [$user, $clinique] = $this->recipient($to);
        // Sa propre organisation : le dossier y est déjà commun ; un vétérinaire qui travaille aussi ailleurs reçoit la lettre là-bas
        $isOwn = $clinique !== null
            ? $clinique->societe?->organisation_id === $letter->organisation_id
            : ! $this->openVetClinics($user)->contains(fn (Rattachement $r): bool => $r->employe->organisation_id !== $letter->organisation_id);
        if ($isOwn) {
            throw ValidationException::withMessages(['recipient' => 'C’est votre organisation : le dossier y est déjà commun. Choisissez une autre clinique ou un confrère.']);
        }

        $invitation = DB::transaction(function () use ($letter, $user, $clinique, $sentBy): ReferralInvitation {
            ReferralLetter::withoutGlobalScopes()->whereKey($letter->id)->lockForUpdate()->first();
            $existing = ReferralInvitation::query()->where('referral_letter_id', $letter->id)->whereIn('status', ['pending', 'accepted'])
                ->when($user, fn (Builder $q) => $q->where('recipient_kind', 'vet')->where('recipient_user_id', $user?->id))
                ->when($clinique, fn (Builder $q) => $q->where('recipient_kind', 'clinic')->where('recipient_clinique_id', $clinique?->id))
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['recipient' => 'Ce destinataire a déjà la lettre.']);
            }

            return ReferralInvitation::query()->create([
                'referral_letter_id' => $letter->id,
                'organisation_id' => $letter->organisation_id,
                'clinique_id' => $letter->clinique_id,
                'patient_id' => $letter->patient_id,
                'sent_by_name' => $sentBy,
                'recipient_kind' => $user ? 'vet' : 'clinic',
                'recipient_user_id' => $user?->id,
                'recipient_clinique_id' => $clinique?->id,
                'recipient_name' => $user ? $this->nameOf($user) : $clinique?->nom,
            ]);
        });

        $this->mail($invitation, $user, $clinique);
        $invitation->announce();

        return $invitation;
    }

    /**
     * @param  array{kind: string, user_id?: int|null, email?: string|null, clinique_id?: int|null}  $to
     * @return array{0: User|null, 1: Clinique|null}
     */
    private function recipient(array $to): array
    {
        if ($to['kind'] === 'clinic') {
            $clinique = Clinique::withoutGlobalScopes()->ouvertes()->find($to['clinique_id'] ?? 0);

            return $clinique ? [null, $clinique] : throw ValidationException::withMessages(['recipient' => 'Cette clinique n’est pas sur Veterinary.']);
        }
        if (! empty($to['email'])) {
            $user = $this->vetByEmail((string) $to['email']);

            return $user ? [$user, null] : throw ValidationException::withMessages(['recipient' => 'Aucun vétérinaire sur Veterinary avec cette adresse : envoyez-lui le lien.']);
        }
        // Par l'annuaire : un vétérinaire d'une clinique ouverte qui montre ses vétérinaires, comme l'annuaire le propose
        $user = User::query()->find($to['user_id'] ?? 0);
        $isListed = $user !== null && $this->openVetClinics($user)->contains(fn (Rattachement $r): bool => (bool) $r->clinique->affiche_veterinaires);

        return $isListed ? [$user, null] : throw ValidationException::withMessages(['recipient' => 'Ce vétérinaire n’est pas dans l’annuaire.']);
    }

    private function nameOf(User $user): string
    {
        return (string) ($this->vetClinics($user)->first()?->employe->nom ?: $user->name);
    }

    /** Par e-mail : au vétérinaire, ou à l'adresse de la clinique. Un e-mail qui ne part pas n'annule pas l'invitation */
    private function mail(ReferralInvitation $invitation, ?User $user, ?Clinique $clinique): void
    {
        $address = $user->email ?? $clinique->email ?? null;
        if (! $address) {
            return;
        }
        try {
            Mail::to($address)->send(new ReferralInvitationMail(
                $invitation,
                Clinique::withoutGlobalScopes()->findOrFail($invitation->clinique_id),
                Patient::withoutGlobalScopes()->findOrFail($invitation->patient_id),
                rtrim((string) config('app.frontend_url'), '/').'/clinique/messages?invitation='.$invitation->id,
            ));
        } catch (Throwable $error) {
            Log::warning('Invitation de référence : e-mail non parti', ['invitation' => $invitation->id, 'error' => $error->getMessage()]);
        }
    }

    /** Le destinataire peut-il répondre, depuis la clinique où il travaille maintenant ? */
    public function canAnswer(ReferralInvitation $invitation, User $user, ?int $cliniqueId): bool
    {
        // Depuis une de ses cliniques hors de l'organisation qui réfère
        if ($invitation->recipient_kind === 'vet') {
            return $invitation->recipient_user_id === $user->id
                && $this->vetClinics($user)->contains(fn (Rattachement $r): bool => $r->clinique->id === $cliniqueId && $r->employe->organisation_id !== $invitation->organisation_id);
        }

        return $invitation->recipient_clinique_id === $cliniqueId;
    }

    /** Accepter, depuis la clinique où il travaille ; l'animal se choisit ou se crée ensuite (`link`) */
    public function accept(ReferralInvitation $invitation, int $cliniqueId, User $user, ?string $byName): ReferralInvitation
    {
        DB::transaction(function () use ($invitation, $cliniqueId, $user, $byName): void {
            $locked = ReferralInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['invitation' => self::closed($locked)]);
            }
            $locked->forceFill([
                'status' => 'accepted', 'accepted_at' => now(), 'accepted_by_id' => $user->id, 'accepted_by_name' => $byName ?? $user->name,
                'target_clinique_id' => $cliniqueId,
            ])->save();
        });

        $invitation = $invitation->refresh();
        $invitation->announce();

        return $invitation;
    }

    /** L'animal choisi ou créé chez lui devient un patient référé : la lettre et ses pièces se consultent depuis son dossier */
    public function link(ReferralInvitation $invitation, Patient $target): ReferralInvitation
    {
        DB::transaction(function () use ($invitation, $target): void {
            $locked = ReferralInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'accepted' || $locked->target_patient_id !== null) {
                throw ValidationException::withMessages(['invitation' => $locked->status === 'accepted' ? 'L’animal est déjà choisi.' : self::closed($locked)]);
            }
            $locked->forceFill(['target_patient_id' => $target->id])->save();
        });

        $invitation = $invitation->refresh();
        $invitation->announce();

        return $invitation;
    }

    public function refuse(ReferralInvitation $invitation, ?string $reason, ?string $byName): ReferralInvitation
    {
        DB::transaction(function () use ($invitation, $reason, $byName): void {
            $locked = ReferralInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['invitation' => self::closed($locked)]);
            }
            $locked->forceFill(['status' => 'refused', 'refused_at' => now(), 'refused_by_name' => $byName, 'refusal_reason' => $reason ? mb_substr(trim($reason), 0, 500) : null])->save();
        });

        $invitation = $invitation->refresh();
        $invitation->announce();

        return $invitation;
    }

    /** Couper : en attente, l'invitation disparaît ; acceptée, la lettre et les pièces ne se consultent plus */
    public function revoke(ReferralInvitation $invitation, ?string $byName): ReferralInvitation
    {
        DB::transaction(function () use ($invitation, $byName): void {
            $locked = ReferralInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['pending', 'accepted'], true)) {
                $locked->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_name' => $byName])->save();
            }
        });

        $invitation = $invitation->refresh();
        $invitation->announce();

        return $invitation;
    }

    public static function closed(ReferralInvitation $invitation): string
    {
        return match ($invitation->status) {
            'accepted' => 'Cette invitation a déjà été acceptée.',
            'refused' => 'Cette invitation a été refusée.',
            default => 'La clinique qui réfère a coupé cette invitation.',
        };
    }
}
