<?php

namespace App\Models;

use Database\Factories\CommandeFournisseurLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un bon de commande — sans scope propre : elle se résout
 * toujours via sa commande. `cout` fige le prix d'achat du moment.
 */
class CommandeFournisseurLigne extends Model
{
    /** @use HasFactory<CommandeFournisseurLigneFactory> */
    use HasFactory;

    protected $table = 'commande_fournisseur_lignes';

    protected $fillable = ['commande_fournisseur_id', 'produit_id', 'quantite', 'cout', 'reason_stock', 'reason_threshold', 'reason_at', 'moved_from'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'cout' => 'decimal:2',
            // Pourquoi il est dans le panier automatique (03.11.2026)
            'reason_stock' => 'integer',
            'reason_threshold' => 'integer',
            'reason_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CommandeFournisseur, $this> */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
