<?php

namespace App\Models;

use Database\Factories\CommandeBoutiqueLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une commande boutique — sans scope propre : elle se résout
 * toujours via sa commande.
 */
class CommandeBoutiqueLigne extends Model
{
    /** @use HasFactory<CommandeBoutiqueLigneFactory> */
    use HasFactory;

    protected $table = 'commande_boutique_lignes';

    protected $fillable = ['commande_boutique_id', 'produit_id', 'quantite', 'prix_chf'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_chf' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CommandeBoutique, $this> */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeBoutique::class, 'commande_boutique_id');
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
