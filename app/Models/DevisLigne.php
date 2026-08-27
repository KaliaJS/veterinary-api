<?php

namespace App\Models;

use App\Enums\TypeLigneFacture;
use Database\Factories\DevisLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de devis — sans scope propre : elle se résout toujours via son
 * devis. Même forme qu'une ligne de facture.
 */
class DevisLigne extends Model
{
    /** @use HasFactory<DevisLigneFactory> */
    use HasFactory;

    protected $fillable = ['devis_id', 'libelle', 'quantite', 'prix_chf', 'type', 'produit_id'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_chf' => 'decimal:2',
            'type' => TypeLigneFacture::class,
        ];
    }

    /** @return BelongsTo<Devis, $this> */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
