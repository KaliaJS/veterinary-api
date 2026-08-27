<?php

namespace App\Models;

use Database\Factories\SejourSoinFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Soin du plan de soins d'un séjour. Enfant du dossier : pas de scope
 * propre, toujours résolu via le séjour. « Soin fait » horodate, signe
 * et sort le stock de la clinique du séjour si un produit est lié.
 */
class SejourSoin extends Model
{
    /** @use HasFactory<SejourSoinFactory> */
    use HasFactory;

    protected $fillable = [
        'sejour_id', 'prevu_a', 'acte', 'produit_id',
        'fait', 'fait_le', 'fait_par_id',
    ];

    protected function casts(): array
    {
        return [
            'prevu_a' => 'integer',
            'fait' => 'boolean',
            'fait_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
