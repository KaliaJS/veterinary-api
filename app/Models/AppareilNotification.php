<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un appareil à prévenir par Apple (APNs) : la montre d'un membre de l'équipe (28.10.2026). */
class AppareilNotification extends Model
{
    protected $table = 'appareils_notifications';

    protected $fillable = ['user_id', 'personal_access_token_id', 'jeton', 'environnement', 'plateforme'];

    /** La session qui l'a enregistré (02.11.2026) @return BelongsTo<JetonApi, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(JetonApi::class, 'personal_access_token_id');
    }

    /**
     * Les appareils dont la session est encore ouverte (ou d'avant le
     * 02.11.2026, sans session notée).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActifs(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('personal_access_token_id')
            ->orWhereHas('session', fn (Builder $t) => $t->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
