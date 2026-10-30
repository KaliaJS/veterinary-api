<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Certificat de visionnage (31.10.2026) : figé à sa délivrance, vérifiable par son code. */
class ViewingCertificate extends Model
{
    protected $fillable = ['code', 'video_id', 'user_id', 'holder_name', 'holder_gln', 'video_title', 'seller_name', 'duration_seconds', 'watched_at'];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'decimal:3',
            'watched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
