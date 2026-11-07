<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Le fichier d'un message audio ou vidéo (07.11.2026) : du fil d'un client,
 * entre collègues ou entre cliniques (un seul des trois messages). Pas de
 * scope global, comme ses messages : toujours lu avec un filtre explicite
 * (MessageMediaController, le compte côté espace). `clinique_id` : la
 * clinique d'où il est parti (stockage de son organisation). Le fichier
 * s'efface avec la ligne ; une ligne effacée en cascade par la base laisse
 * son fichier à la purge de la nuit (messages:purge-media).
 * La transcription (texte chiffré) n'est servie qu'à l'équipe de la
 * clinique ; sa durée compte dans l'utilisation de l'organisation qui l'a
 * demandée, avec celle des consultations.
 *
 * @property list<float> $peaks
 */
class MessageMedia extends Model
{
    protected $table = 'message_media';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DONE = 'done';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_clientele_id', 'staff_message_id', 'clinic_message_id', 'clinique_id', 'kind', 'path', 'erased_at', 'mime', 'size_bytes', 'duration_ms', 'peaks',
        'transcript_status', 'transcript', 'transcript_seconds', 'provider_seconds', 'provider_at', 'transcribed_at', 'transcript_organisation_id',
        'transcript_requested_by', 'transcript_request_id', 'transcript_attempts',
    ];

    protected static function booted(): void
    {
        // Données personnelles : le fichier part avec sa ligne, une fois la base
        // validée (jamais si elle annule) ; tout de suite hors transaction
        static::deleted(function (MessageMedia $media): void {
            $path = $media->path;
            if ($path !== null) {
                DB::afterCommit(fn () => Storage::disk('prive')->delete($path));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'peaks' => 'array',
            'transcript' => 'encrypted',
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'transcript_seconds' => 'integer',
            'transcript_attempts' => 'integer',
            'transcribed_at' => 'datetime',
            'provider_seconds' => 'integer',
            'provider_at' => 'datetime',
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

    public function isTranscribed(): bool
    {
        return $this->transcript_status === self::STATUS_DONE;
    }
}
