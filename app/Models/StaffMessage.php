<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        static::deleting(function (self $message): void {
            $message->media()->first()?->delete();
            // Ses pièces jointes aussi (08.11.2026), une à une pour que leurs fichiers partent
            $message->attachments()->get()->each->delete();
        });
    }

    protected $fillable = ['organisation_id', 'sender_employee_id', 'recipient_employee_id', 'body', 'read_at', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    /** Les pièces jointes, photos et PDF (08.11.2026), dans leur ordre
     * @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'staff_message_id')->orderBy('position')->orderBy('id');
    }
}
