<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot de péremption détenu par une clinique (FEFO). Écarter un lot
 * (suppression tolérée) sort le stock avec un mouvement motivé
 * « Péremption » puis supprime la ligne.
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $fillable = ['produit_id', 'clinique_id', 'numero_lot', 'peremption_le', 'quantite'];

    protected function casts(): array
    {
        return [
            'peremption_le' => 'date',
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

    /** Péremption rendue au format du front « MM.YYYY ». */
    public function peremptionAffichee(): string
    {
        return $this->peremption_le->format('m.Y');
    }
}
