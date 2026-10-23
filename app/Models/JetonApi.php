<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton Sanctum lié à une clinique : le middleware `clinique` en
 * dérive le contexte appliqué globalement (scopes multi-clinique).
 *
 * @property int|null $clinique_id
 * @property \Illuminate\Support\Carbon|null $session_depuis
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class JetonApi extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** Une session dure au plus ce nombre de jours, rafraîchissements compris */
    public const int SESSION_MAX_JOURS = 30;

    protected function casts(): array
    {
        return [...parent::casts(), 'session_depuis' => 'datetime'];
    }

    /** Début de la session (la connexion d'origine). */
    public function debutSession(): Carbon
    {
        return $this->session_depuis ?? $this->created_at ?? now();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
