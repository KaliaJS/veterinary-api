<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected static function booted(): void
    {
        // Effacé par le modèle : son fichier audio ou vidéo part avec lui (la cascade de la base le laisserait)
        static::deleting(function (self $message): void {
            $message->media()->first()?->delete();
            // Ses pièces jointes aussi (08.11.2026), une à une pour que leurs fichiers partent
            $message->attachments()->get()->each->delete();
        });
    }

    protected $fillable = ['clinique_id', 'user_id', 'client_id', 'employe_id', 'auteur', 'texte', 'lu_le', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'lu_le' => 'datetime',
            'delivered_at' => 'datetime',
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

    /** Le fichier d'un message audio ou vidéo (07.11.2026) ; aucun pour un message écrit
     * @return HasOne<MessageMedia, $this> */
    public function media(): HasOne
    {
        return $this->hasOne(MessageMedia::class, 'message_clientele_id');
    }

    /** Les pièces jointes, photos et PDF (08.11.2026), dans leur ordre
     * @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_clientele_id')->orderBy('position')->orderBy('id');
    }
}
