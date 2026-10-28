<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
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
        static::deleted(fn (Stock $stock) => TempsReel::alertes((int) $stock->clinique_id));
    }

    protected $fillable = ['produit_id', 'clinique_id', 'quantite'];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
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
