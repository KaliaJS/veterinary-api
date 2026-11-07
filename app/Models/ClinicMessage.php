<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Message entre deux cliniques (01.11.2026) : lu par toute l'équipe de
 * chacune. Pas de scope global : toujours lu avec un filtre explicite sur la
 * clinique du contexte (InternalMessaging).
 */
class ClinicMessage extends Model
{
    protected static function booted(): void
    {
        // Effacé par le modèle : son fichier audio ou vidéo part avec lui (la cascade de la base le laisserait)
        static::deleting(fn (self $message) => $message->media()->first()?->delete());
    }

    protected $fillable = ['sender_clinic_id', 'recipient_clinic_id', 'author_employee_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'author_employee_id')->withoutGlobalScopes();
    }

    /** Le fichier d'un message audio ou vidéo (07.11.2026) ; aucun pour un message écrit
     * @return HasOne<MessageMedia, $this> */
    public function media(): HasOne
    {
        return $this->hasOne(MessageMedia::class, 'clinic_message_id');
    }
}
