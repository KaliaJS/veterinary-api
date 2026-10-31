<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visionnage d'une vidéo par une personne (31.10.2026) : les secondes
 * regardées, une vue comptée à plus d'une minute (une seule fois), vue en
 * entier à 95 %.
 */
class VideoView extends Model
{
    /** Une vue compte à plus d'une minute regardée. */
    public const COUNT_AFTER_SECONDS = 60;

    /** Vue en entier : 95 % de la durée, ce qui ouvre le certificat. */
    public const COMPLETION_RATIO = 0.95;

    /**
     * Les secondes à avoir regardées pour le certificat : 95 % arrondis au-dessus,
     * jamais plus que les secondes entières de la vidéo (les secondes se créditent
     * entières : 10,9 secondes regardées comptent 10).
     */
    public static function requiredSeconds(float $duration): int
    {
        return (int) min(ceil($duration * self::COMPLETION_RATIO), floor($duration));
    }

    protected $fillable = ['video_id', 'user_id', 'watched_ranges', 'watched_seconds', 'last_progress_at', 'counted_at', 'counted_via_plus', 'completed_at'];

    protected function casts(): array
    {
        return [
            'watched_ranges' => 'array',
            'last_progress_at' => 'datetime',
            'counted_at' => 'datetime',
            'counted_via_plus' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
