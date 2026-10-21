<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitation à rejoindre une organisation (voir la migration). Le jeton
 * du lien n'est jamais stocké en clair : `nouveauJeton()` le rend une
 * fois, pour l'e-mail, et n'en garde que l'empreinte.
 */
class InvitationEmploye extends Model
{
    protected $table = 'invitations_employe';

    public const DUREE_JOURS = 14;

    protected $fillable = ['organisation_id', 'email', 'nom', 'donnees', 'invitee_par', 'expire_le'];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'expire_le' => 'datetime',
            'acceptee_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    /** Nouveau jeton (renvoi compris) : l'ancien lien cesse de marcher. */
    public function nouveauJeton(): string
    {
        $jeton = Str::random(48);
        $this->forceFill([
            'jeton_empreinte' => hash('sha256', $jeton),
            'expire_le' => now()->addDays(self::DUREE_JOURS),
        ])->save();

        return $jeton;
    }

    public static function duJeton(string $jeton): ?self
    {
        return self::query()->where('jeton_empreinte', hash('sha256', $jeton))->first();
    }

    public function estEnAttente(): bool
    {
        return $this->acceptee_le === null && $this->annulee_le === null && $this->expire_le->isFuture();
    }

    /** @param  Builder<InvitationEmploye>  $requete */
    public function scopeEnAttente(Builder $requete): void
    {
        $requete->whereNull('acceptee_le')->whereNull('annulee_le')->where('expire_le', '>', now());
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function invitant(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'invitee_par');
    }
}
