<?php

namespace App\Models;

use App\Enums\StatutIntervention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passage de statut du cycle opératoire — journal immuable (création
 * seule, UPDATED_AT null), comme les audits de rendez-vous.
 */
class InterventionTransition extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['intervention_id', 'statut', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'statut' => StatutIntervention::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
