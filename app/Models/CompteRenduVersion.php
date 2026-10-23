<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Version remplacée d'un compte rendu SOAP non signé (23.10.2026). */
class CompteRenduVersion extends Model
{
    protected $table = 'comptes_rendus_versions';

    protected $fillable = ['compte_rendu_id', 's', 'o', 'a', 'p', 'user_id', 'redigee_le'];

    protected function casts(): array
    {
        return ['redigee_le' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
