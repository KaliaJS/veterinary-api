<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le retour au vétérinaire référent (09.11.2026) : les pièces signées que
 * le confrère renvoie à la clinique qui a référé, depuis le dossier de
 * l'animal référé. Rien n'est copié. Il traverse les organisations : pas de
 * portée globale ; lu côté confrère par son organisation, côté clinique qui
 * a référé par la sienne (filtres explicites, ReferralReturnController).
 */
class ReferralReturn extends Model
{
    protected $table = 'referral_returns';

    protected $fillable = ['referral_invitation_id', 'clinique_id', 'patient_id', 'items', 'note', 'sent_by_name', 'opened_at', 'revoked_at', 'revoked_by_name'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'note' => 'encrypted',
            'opened_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReferralInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ReferralInvitation::class, 'referral_invitation_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class)->withoutGlobalScopes();
    }
}
