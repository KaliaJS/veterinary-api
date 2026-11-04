<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use App\Services\Purchasing\AutoOrdering;
use App\Support\TempsReel;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantité d'un produit dans UNE clinique (pivot produit × clinique).
 * Jamais négative — les sorties sont bornées à zéro et l'écart réel est
 * celui du journal `mouvements_stock`. Toute mutation passe par
 * GestionnaireStock, jamais par un update direct.
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use AppartientAUneClinique, HasFactory;

    protected static function booted(): void
    {
        // Alertes en temps réel (28.10.2026) : le stock de la clinique a changé
        static::saved(fn (Stock $stock) => TempsReel::alertes((int) $stock->clinique_id));
        // Commande automatique (03.11.2026) : toute sortie compte, une fois la transaction validée
        static::saved(function (Stock $stock): void {
            if ($stock->wasChanged('quantite') || $stock->wasRecentlyCreated) {
                AutoOrdering::afterStockChange($stock, (int) $stock->getOriginal('quantite'));
            }
        });
        static::deleted(fn (Stock $stock) => TempsReel::alertes((int) $stock->clinique_id));
    }

    protected $fillable = ['produit_id', 'clinique_id', 'quantite', 'opened_rest', 'opening_number', 'opened_at', 'last_opening_number', 'last_count_id', 'last_counted_at', 'manual_order_since', 'manual_order_reason'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            // Doses restant dans l'unité entamée (03.11.2026)
            'opened_rest' => 'float',
            'opening_number' => 'integer',
            'opened_at' => 'datetime',
            // Sous le verrou de la ligne (neuvième vérification)
            'last_opening_number' => 'integer',
            'last_count_id' => 'integer',
            'last_counted_at' => 'datetime',
            // À commander à la main : indisponible chez sa centrale, sans autre source (03.11.2026)
            'manual_order_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
