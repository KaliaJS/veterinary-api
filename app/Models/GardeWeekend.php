<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\GardeWeekendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réattribution d'une garde de week-end (un samedi → un employé). La
 * rotation par défaut est DÉRIVÉE (semaine modulo l'effectif
 * vétérinaire — RotationGardes) : seule une réattribution est stockée.
 */
class GardeWeekend extends Model
{
    /** @use HasFactory<GardeWeekendFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'gardes_weekend';

    protected $fillable = ['clinique_id', 'samedi_le', 'employe_id'];

    protected function casts(): array
    {
        return [
            'samedi_le' => 'date',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
