<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton Sanctum lié à une clinique : le middleware `clinique` en
 * dérive le contexte appliqué globalement (scopes multi-clinique).
 */
class JetonApi extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
