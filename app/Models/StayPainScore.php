<?php

namespace App\Models;

use App\Enums\PainScale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une évaluation de la douleur sur l'échelle de l'espèce (03.11.2026) :
 * l'échelle, son maximum et son seuil sont gardés avec le score.
 */
class StayPainScore extends Model
{
    protected $table = 'stay_pain_scores';

    protected $fillable = ['sejour_id', 'day', 'minute', 'scale', 'score', 'max', 'threshold', 'note', 'by_id', 'by_name', 'late_noted_at'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'minute' => 'integer',
            'late_noted_at' => 'datetime',
            'scale' => PainScale::class,
            'score' => 'integer',
            'max' => 'integer',
            'threshold' => 'integer',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }
}
