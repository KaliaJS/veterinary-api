<?php

namespace App\Models;

use Database\Factories\SejourConstanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relevé de constantes d'un séjour (température, FC, FR) à une heure
 * locale d'un jour de la clinique. Enfant du dossier : résolu via le séjour.
 */
class SejourConstante extends Model
{
    /** @use HasFactory<SejourConstanteFactory> */
    use HasFactory;

    protected $fillable = ['sejour_id', 'jour', 'mesure_a', 'temperature', 'fc', 'fr', 'by_id', 'by_name', 'late_noted_at'];

    protected function casts(): array
    {
        return [
            'jour' => 'date',
            'mesure_a' => 'integer',
            'temperature' => 'decimal:1',
            'fc' => 'integer',
            'fr' => 'integer',
            'late_noted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }
}
