<?php

namespace App\Models;

use Database\Factories\OrdonnanceLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de prescription — sans scope propre : elle se résout toujours
 * via son ordonnance.
 */
class OrdonnanceLigne extends Model
{
    /** @use HasFactory<OrdonnanceLigneFactory> */
    use HasFactory;

    protected $fillable = ['ordonnance_id', 'produit_id', 'posologie', 'duree'];

    /** @return BelongsTo<Ordonnance, $this> */
    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
