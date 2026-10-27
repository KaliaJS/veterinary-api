<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Le produit Vetpoint relié à un produit du catalogue (27.10.2026). */
class ProduitVetpoint extends Model
{
    protected $table = 'produits_vetpoint';

    protected $fillable = ['produit_id', 'vetpoint_id', 'nom', 'swissmedic'];

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
