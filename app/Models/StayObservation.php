<?php

namespace App\Models;

use App\Enums\ObservationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mange, boit, urine, selles, vomit (03.11.2026) : une valeur courte,
 * l'heure locale et qui l'a noté.
 */
class StayObservation extends Model
{
    protected $table = 'stay_observations';

    protected $fillable = ['sejour_id', 'day', 'minute', 'kind', 'value', 'by_id', 'by_name', 'late_noted_at'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'minute' => 'integer',
            'late_noted_at' => 'datetime',
            'kind' => ObservationKind::class,
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }
}
