<?php

namespace App\Models;

use App\Events\ReferralInvitationUpdated;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La lettre de référence envoyée dans Veterinary, envoi C (09.11.2026) : à
 * un vétérinaire (`recipient_user_id`) ou à toute une clinique
 * (`recipient_clinique_id`). Elle traverse les organisations : pas de
 * portée globale, chaque lecture passe par `forRecipient` ou par la
 * clinique qui réfère. Acceptée, l'animal du destinataire
 * (`target_patient_id`) consulte la lettre et ses pièces, sans copie,
 * jusqu'à ce que la clinique qui réfère coupe l'accès.
 */
class ReferralInvitation extends Model
{
    protected $table = 'referral_invitations';

    protected $fillable = [
        'referral_letter_id', 'organisation_id', 'clinique_id', 'patient_id', 'sent_by_name',
        'recipient_kind', 'recipient_user_id', 'recipient_clinique_id', 'recipient_name', 'status', 'opened_at',
        'accepted_at', 'accepted_by_id', 'accepted_by_name', 'target_clinique_id', 'target_patient_id',
        'refused_at', 'refused_by_name', 'refusal_reason', 'revoked_at', 'revoked_by_name',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReferralLetter, $this> */
    public function letter(): BelongsTo
    {
        return $this->belongsTo(ReferralLetter::class, 'referral_letter_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function targetClinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class, 'target_clinique_id')->withoutGlobalScopes();
    }

    /**
     * Ce qu'un destinataire voit : les invitations à lui, ou à la clinique où il travaille maintenant
     *
     * @param  Builder<ReferralInvitation>  $query
     * @return Builder<ReferralInvitation>
     */
    public function scopeForRecipient(Builder $query, int $userId, ?int $cliniqueId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $v) => $v->where('recipient_kind', 'vet')->where('recipient_user_id', $userId))
            ->orWhere(fn (Builder $c) => $c->where('recipient_kind', 'clinic')->where('recipient_clinique_id', $cliniqueId ?? 0)));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** La lettre et ses pièces se consultent : acceptée, et pas coupée */
    public function isOpenToTarget(): bool
    {
        return $this->status === 'accepted';
    }

    /** Le destinataire relit ses invitations, la clinique qui réfère sa lettre (Reverb), après la transaction */
    public function announce(): void
    {
        $invitation = $this;
        TempsReel::diffuser(function () use ($invitation): void {
            broadcast(new ReferralInvitationUpdated($invitation->id, $invitation->recipientChannels()));
        });
        ReferralLetter::announce((int) $this->referral_letter_id, (int) $this->clinique_id);
    }

    /** @return list<string> */
    public function recipientChannels(): array
    {
        return array_values(array_filter([
            $this->recipient_user_id ? 'utilisateur.'.$this->recipient_user_id : null,
            $this->recipient_clinique_id ? 'clinique.'.$this->recipient_clinique_id : null,
            $this->target_clinique_id ? 'clinique.'.$this->target_clinique_id : null,
        ]));
    }
}
