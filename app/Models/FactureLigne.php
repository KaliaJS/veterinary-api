<?php

namespace App\Models;

use App\Enums\TypeLigneFacture;
use Database\Factories\FactureLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de facture — sans scope propre : elle se résout toujours via sa
 * facture. Prix unitaire TTC signé (remise et acompte en négatif).
 */
class FactureLigne extends Model
{
    /** @use HasFactory<FactureLigneFactory> */
    use HasFactory;

    protected $fillable = ['facture_id', 'libelle', 'quantite', 'prix_chf', 'type', 'produit_id'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_chf' => 'decimal:2',
            'type' => TypeLigneFacture::class,
        ];
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
