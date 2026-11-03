<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une prise (ou un soin) donnée puis annulée (sixième vérification,
 * 03.11.2026) : gardée dans l'historique quand la prise se note de nouveau.
 *
 * @property int $id
 * @property int $sejour_soin_id
 * @property Carbon|null $given_at
 * @property string|null $given_by_name
 * @property float|null $dose_amount
 * @property string|null $dose_unit
 * @property Carbon|null $late_noted_at
 * @property Carbon $cancelled_at
 * @property int|null $cancelled_by_id
 * @property string|null $cancelled_by_name
 * @property bool $cancelled_late
 */
class StayCareCancellation extends Model
{
    protected $fillable = [
        'sejour_soin_id', 'given_at', 'given_by_name', 'dose_amount', 'dose_unit', 'late_noted_at',
        'cancelled_at', 'cancelled_by_id', 'cancelled_by_name', 'cancelled_late',
    ];

    protected function casts(): array
    {
        return [
            'given_at' => 'datetime',
            'dose_amount' => 'float',
            'late_noted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_late' => 'boolean',
        ];
    }

    /** @return BelongsTo<SejourSoin, $this> */
    public function soin(): BelongsTo
    {
        return $this->belongsTo(SejourSoin::class, 'sejour_soin_id');
    }
}
