<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La commande automatique d'un article dans une clinique (décision de Jim,
 * 04.11.2026) : l'interrupteur, jusqu'où remonter, et l'écart décidé par la
 * clinique (article retiré d'un panier automatique, panier supprimé) avec sa
 * raison et qui l'a fait. L'écart tombe quand le stock repasse au-dessus du
 * seuil. Sans scope : la ligne dit sa clinique.
 */
class ProductOrderSetting extends Model
{
    protected $attributes = [
        'is_auto_order' => false,
    ];

    protected $fillable = ['produit_id', 'clinique_id', 'is_auto_order', 'reorder_up_to', 'excluded_at', 'excluded_reason', 'excluded_by'];

    protected function casts(): array
    {
        return [
            'is_auto_order' => 'boolean',
            'reorder_up_to' => 'integer',
            'excluded_at' => 'datetime',
            'excluded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    /** Réglé « automatique » et pas écarté par la clinique */
    public function isActive(): bool
    {
        return $this->is_auto_order && $this->excluded_at === null;
    }
}
