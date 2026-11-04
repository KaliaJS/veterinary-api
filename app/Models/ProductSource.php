<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une source d'un article (03.11.2026) : un fournisseur de la clinique, sa
 * place dans l'ordre de préférence, la référence et le prix d'achat chez lui.
 * Sans scope propre : elle se lit par son fournisseur, donc par sa clinique.
 */
class ProductSource extends Model
{
    protected $fillable = ['produit_id', 'supplier_id', 'rank', 'reference', 'cost'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
