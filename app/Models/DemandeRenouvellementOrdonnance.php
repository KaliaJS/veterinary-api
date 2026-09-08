<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de renouvellement d'ordonnance déposée par le propriétaire
 * depuis son espace ; en attente tant que `traitee_le` est nul.
 */
class DemandeRenouvellementOrdonnance extends Model
{
    protected $table = 'demandes_renouvellement_ordonnance';

    protected $fillable = ['ordonnance_id', 'user_id', 'traitee_le'];

    protected function casts(): array
    {
        return [
            'traitee_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ordonnance, $this> */
    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
