<?php

namespace App\Models;

use App\Enums\PieceNumerotee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur annuel d'une pièce numérotée pour une organisation
 * (docs/data-model.md §18). Jamais exposé par l'API : seul le
 * Numeroteur y touche, sous verrou.
 */
class SequenceNumerotation extends Model
{
    protected $table = 'sequences_numerotation';

    protected $fillable = ['organisation_id', 'piece', 'annee', 'prochain'];

    /** Miroir du défaut de la base — un compteur neuf part à 1. */
    protected $attributes = [
        'prochain' => 1,
    ];

    protected function casts(): array
    {
        return [
            'piece' => PieceNumerotee::class,
            'annee' => 'integer',
            'prochain' => 'integer',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
