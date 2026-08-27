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

    protected $fillable = ['commande_fournisseur_id', 'produit_id', 'quantite', 'cout'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'cout' => 'decimal:2',
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
