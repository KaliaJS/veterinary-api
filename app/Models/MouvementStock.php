<?php

namespace App\Models;

use App\Enums\MotifAjustementStock;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\MouvementStockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ligne du journal de stock — immuable (UPDATED_AT null, comme les
 * audits de rendez-vous). `quantite` est l'écart réellement appliqué ;
 * un undo est un mouvement inverse, jamais un effacement.
 */
class MouvementStock extends Model
{
    /** @use HasFactory<MouvementStockFactory> */
    use AppartientAUneClinique, HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'mouvements_stock';

    protected $fillable = [
        'produit_id', 'clinique_id', 'user_id', 'quantite', 'libelle',
        'motif', 'source_type', 'source_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'motif' => MotifAjustementStock::class,
            'created_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Acte à l'origine du geste (facture, ordonnance, commande, lot).
     * @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
