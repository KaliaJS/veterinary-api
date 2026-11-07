<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lot de péremption détenu par une clinique (FEFO). Chaque sortie de
 * stock le décompte, et une annulation lui rend ce qu'elle avait pris
 * (FefoLots, 04.11.2026) ; un lot vide reste, pour la trace. Écarter un
 * lot (suppression tolérée) sort le stock avec un mouvement motivé
 * « Péremption » puis supprime la ligne.
 *
 * @property Carbon|null $peremption_le vide pour le matériel et les accessoires (migration 000213)
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use AppartientAUneClinique, HasFactory;

    protected static function booted(): void
    {
        // Alertes en temps réel (28.10.2026) : les lots de la clinique ont changé
        static::saved(fn (Lot $lot) => TempsReel::alertes((int) $lot->clinique_id));
        static::deleted(fn (Lot $lot) => TempsReel::alertes((int) $lot->clinique_id));
    }

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

    /**
     * Ce que les sorties ont pris dans ce lot, et ce qui lui a été rendu
     *
     * @return HasMany<LotAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LotAllocation::class);
    }

    /** Péremption rendue au format du front « MM.YYYY ». */
    public function peremptionAffichee(): ?string
    {
        // Un lot de matériel ou d'accessoires peut ne pas en avoir (04.11.2026)
        return $this->peremption_le?->format('m.Y');
    }
}
