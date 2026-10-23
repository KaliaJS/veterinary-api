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

    protected $fillable = ['sejour_id', 'jour', 'mesure_a', 'temperature', 'fc', 'fr'];

    protected function casts(): array
    {
        return [
            'jour' => 'date',
            'mesure_a' => 'integer',
            'temperature' => 'decimal:1',
            'fc' => 'integer',
            'fr' => 'integer',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }
}
