<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Une pièce jointe d'un message (08.11.2026) : une photo ou un PDF, du fil
 * d'un client, entre collègues ou entre cliniques (un seul des trois
 * messages). Pas de scope global, comme ses messages : toujours lue avec un
 * filtre explicite (MessageAttachmentController, le compte côté espace).
 * Le fichier et son aperçu partent avec la ligne ; une ligne effacée en
 * cascade par la base laisse ses fichiers à la purge de la nuit
 * (messages:purge-media).
 *
 * @property int $id
 * @property string $kind image ou pdf
 * @property string $name
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $path
 * @property string|null $thumb_path
 */
class MessageAttachment extends Model
{
    protected $fillable = [
        'message_clientele_id', 'staff_message_id', 'clinic_message_id', 'clinique_id', 'position', 'kind', 'name', 'mime',
        'size_bytes', 'width', 'height', 'path', 'thumb_path', 'erased_at',
    ];

    protected static function booted(): void
    {
        // Données personnelles : les fichiers partent avec leur ligne, une fois la base validée
        static::deleted(function (MessageAttachment $attachment): void {
            $paths = array_values(array_filter([$attachment->path, $attachment->thumb_path]));
            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk('prive')->delete($paths));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'erased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MessageClientele, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MessageClientele::class, 'message_clientele_id');
    }

    /** @return BelongsTo<StaffMessage, $this> */
    public function staffMessage(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class);
    }

    /** @return BelongsTo<ClinicMessage, $this> */
    public function clinicMessage(): BelongsTo
    {
        return $this->belongsTo(ClinicMessage::class);
    }
}
