<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Achat d'une vidéo (31.10.2026) : définitif, gardé à vie, payé sur le compte du vendeur. */
class VideoPurchase extends Model
{
    protected $fillable = ['video_id', 'user_id', 'amount_cents', 'commission_cents', 'stripe_account_id', 'payment_intent_id', 'terms_accepted_at', 'paid_at'];

    protected function casts(): array
    {
        return [
            'terms_accepted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
