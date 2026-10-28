<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message d'un fil clinique ↔ propriétaire. Pas de scope global : lu
 * depuis l'espace propriétaire (par user_id) ou depuis la clinique
 * (par clinique_id du contexte) avec un filtre explicite.
 *
 * @property int|null $user_id vide quand le compte a été effacé (migration 2026_10_27_000001)
 * @property int|null $client_id la fiche du fil, gardée quand le compte est effacé
 */
class MessageClientele extends Model
{
    protected $table = 'messages_clientele';

    protected $fillable = ['clinique_id', 'user_id', 'client_id', 'employe_id', 'auteur', 'texte', 'lu_le'];

    protected function casts(): array
    {
        return [
            'lu_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
