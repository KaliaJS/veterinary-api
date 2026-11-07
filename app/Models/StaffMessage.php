<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Message entre deux collègues de la même organisation (01.11.2026) : borné à
 * l'organisation du jeton (scope), et toujours lu avec un filtre explicite
 * sur l'employé du contexte (InternalMessaging).
 */
class StaffMessage extends Model
{
    use AppartientAUneOrganisation;

    protected static function booted(): void
    {
        // Effacé par le modèle : son fichier audio ou vidéo part avec lui (la cascade de la base le laisserait)
        static::deleting(fn (self $message) => $message->media()->first()?->delete());
    }

    protected $fillable = ['organisation_id', 'sender_employee_id', 'recipient_employee_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'sender_employee_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Employe, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'recipient_employee_id')->withoutGlobalScopes();
    }

    /** Le fichier d'un message audio ou vidéo (07.11.2026) ; aucun pour un message écrit
     * @return HasOne<MessageMedia, $this> */
    public function media(): HasOne
    {
        return $this->hasOne(MessageMedia::class, 'staff_message_id');
    }
}
