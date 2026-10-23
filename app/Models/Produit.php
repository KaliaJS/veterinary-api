<?php

namespace App\Models;

use App\Enums\CategorieProduit;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogue de NIVEAU ORGANISATION : la fiche est partagée entre les
 * cliniques, les quantités vivent dans `stocks` par clinique
 * (docs/data-model.md §14). Le seuil d'alerte s'évalue sur le stock
 * total de l'organisation, comme au front.
 *
 * Sommes chargées à la demande (`withSum`), absentes sinon :
 *
 * @property-read float|int|string|null $solde  mouvements de stupéfiants (registre)
 * @property-read float|int|string|null $stock_total  stocks de toutes les cliniques (alertes)
 */
class Produit extends Model
{
    /** @use HasFactory<ProduitFactory> */
    use AppartientAUneOrganisation, HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'est_boutique' => false,
        'est_sur_ordonnance' => false,
        'est_stupefiant' => false,
        'est_vedette' => false,
    ];

    protected $fillable = [
        'organisation_id', 'nom', 'categorie', 'reference', 'prix', 'cout',
        'unite', 'seuil_alerte', 'est_boutique', 'est_sur_ordonnance',
        'est_stupefiant', 'peremption_le', 'fournisseur', 'ean',
        'promo_pct', 'est_vedette',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieProduit::class,
            'prix' => 'decimal:2',
            'cout' => 'decimal:2',
            'seuil_alerte' => 'integer',
            'est_boutique' => 'boolean',
            'est_sur_ordonnance' => 'boolean',
            'est_stupefiant' => 'boolean',
            'peremption_le' => 'date',
            'promo_pct' => 'integer',
            'est_vedette' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<Stock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** @return HasMany<MouvementStock, $this> */
    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    /**
     * Registre des stupéfiants.
     *
     * @return HasMany<MouvementStupefiant, $this>
     */
    public function mouvementsStupefiants(): HasMany
    {
        return $this->hasMany(MouvementStupefiant::class);
    }

    /**
     * Stock cumulé de toutes les cliniques de l'organisation — base du
     * seuil d'alerte et des commandes automatiques (parité front).
     */
    public function stockTotal(): int
    {
        $charge = $this->relationLoaded('stocks')
            ? $this->stocks
            : $this->stocks()->withoutGlobalScope('clinique')->get();

        return (int) $charge->sum('quantite');
    }

    public function estSousLeSeuil(): bool
    {
        return $this->stockTotal() <= $this->seuil_alerte;
    }

    /** Péremption rendue au format du front « MM.YYYY ». */
    public function peremptionAffichee(): ?string
    {
        return $this->peremption_le?->format('m.Y');
    }

    /**
     * Pourquoi le produit ne se vend pas sans ordonnance (comptoir,
     * boutique), ou null (23.10.2026). Il se délivre alors depuis la
     * facture de la consultation, avec sa prescription ; un stupéfiant
     * passe en plus par le registre.
     */
    public function refusVenteLibre(): ?string
    {
        return match (true) {
            $this->est_stupefiant => $this->nom.' : stupéfiant, délivré seulement sur ordonnance et inscrit au registre.',
            $this->est_sur_ordonnance => $this->nom.' : sur ordonnance, à délivrer depuis la facture de la consultation.',
            default => null,
        };
    }

    /** Prix vitrine, promotion déduite — le prix vendu en boutique. */
    public function prixEffectif(): float
    {
        return round((float) $this->prix * (1 - $this->promo_pct / 100), 2);
    }
}
