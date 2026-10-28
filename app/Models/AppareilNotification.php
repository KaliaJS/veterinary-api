<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un appareil à prévenir par Apple (APNs) : la montre d'un membre de l'équipe (28.10.2026). */
class AppareilNotification extends Model
{
    protected $table = 'appareils_notifications';

    protected $fillable = ['user_id', 'jeton', 'environnement', 'plateforme'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
