<?php

namespace App\Models;

use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
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

    /** `vat_context` : `sale` pour un médicament remis pour la maison (taux de vente), sinon le soin (03.11.2026) */
    protected $fillable = ['devis_id', 'libelle', 'quantite', 'prix_chf', 'type', 'produit_id', 'vat_context'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_chf' => 'decimal:2',
            'type' => TypeLigneFacture::class,
            'vat_context' => VatContext::class,
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
