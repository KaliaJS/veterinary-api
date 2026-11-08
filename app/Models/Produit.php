<?php

namespace App\Models;

use App\Enums\CategorieProduit;
use App\Enums\VatRate;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Models\Concerns\AUnePhoto;
use App\Services\Catalogue\AtcFromCatalogue;
use App\Services\Isabv\PreparationResolver;
use App\Support\TempsReel;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    use AppartientAUneOrganisation, AUnePhoto, HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'est_boutique' => false,
        'est_sur_ordonnance' => false,
        'est_stupefiant' => false,
        'est_vedette' => false,
    ];

    protected static function booted(): void
    {
        // Le taux de vente proposé selon la famille quand l'écran n'en dit rien (TVA par ligne, 03.11.2026)
        static::creating(function (Produit $produit): void {
            if (! isset($produit->getAttributes()['vat_rate'])) {
                $produit->vat_rate = VatRate::forCategory($produit->categorie);
            }
        });
        // Alertes en temps réel (28.10.2026) : un seuil change pour toute l'organisation
        static::updated(function (Produit $produit): void {
            if ($produit->wasChanged('seuil_alerte')) {
                TempsReel::alertesDeLOrganisation((int) $produit->organisation_id);
            }
        });
    }

    protected $fillable = [
        'organisation_id', 'nom', 'categorie', 'reference', 'prix', 'cout',
        'unite', 'seuil_alerte', 'est_boutique', 'est_sur_ordonnance',
        'est_stupefiant', 'tableau_stupefiant', 'peremption_le', 'fournisseur', 'ean',
        'promo_pct', 'est_vedette', 'atc_code', 'atc_code_is_manual',
        // Médicaments à la dose (03.11.2026) : ce que contient une unité de stock
        'dose_unit', 'content_per_unit',
        // Commande automatique et photo de la vitrine (nouvel article B, 03.11.2026)
        'photo_chemin',
        // Le taux de TVA à la vente (03.11.2026)
        'vat_rate',
        // La phrase de la vitrine, montrée sur le site de la clinique (07.11.2026)
        'shop_description',
        // La préparation SI ABV choisie à la main (08.11.2026)
        'isabv_preparation_code',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieProduit::class,
            'vat_rate' => VatRate::class,
            'atc_code_is_manual' => 'boolean',
            'prix' => 'decimal:2',
            'cout' => 'decimal:2',
            'seuil_alerte' => 'integer',
            'est_boutique' => 'boolean',
            'est_sur_ordonnance' => 'boolean',
            'est_stupefiant' => 'boolean',
            'peremption_le' => 'date',
            'promo_pct' => 'integer',
            'est_vedette' => 'boolean',
            'content_per_unit' => 'float',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Le produit Vetpoint relié (27.10.2026), un au plus.
     *
     * @return HasOne<ProduitVetpoint, $this>
     */
    public function vetpoint(): HasOne
    {
        return $this->hasOne(ProduitVetpoint::class);
    }

    /**
     * Ses sources chez les fournisseurs, toutes cliniques (03.11.2026) ;
     * `sourcesIn` pour celles d'une clinique, par préférence.
     *
     * @return HasMany<ProductSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    /**
     * Les sources de l'article dans une clinique, par préférence.
     *
     * @return Collection<int, ProductSource>
     */
    public function sourcesIn(int $cliniqueId): Collection
    {
        // Lues d'avance (liste du stock) : triées ici, sans requête
        if ($this->relationLoaded('sources') && $this->sources->every(fn (ProductSource $source): bool => $source->relationLoaded('supplier'))) {
            return $this->sources
                ->filter(fn (ProductSource $source): bool => (int) $source->supplier?->clinique_id === $cliniqueId)
                ->sortBy('rank')
                ->values();
        }

        return $this->sources()
            ->whereHas('supplier', fn ($supplier) => $supplier->withoutGlobalScopes()->where('clinique_id', $cliniqueId))
            ->with(['supplier' => fn ($supplier) => $supplier->withoutGlobalScopes()])
            ->orderBy('rank')
            ->get();
    }

    /**
     * La commande automatique par clinique (04.11.2026).
     *
     * @return HasMany<ProductOrderSetting, $this>
     */
    public function orderSettings(): HasMany
    {
        return $this->hasMany(ProductOrderSetting::class);
    }

    /** Le réglage de la commande automatique dans une clinique, s'il y en a un */
    public function orderSettingIn(int $cliniqueId): ?ProductOrderSetting
    {
        $setting = $this->relationLoaded('orderSettings')
            ? $this->orderSettings->firstWhere('clinique_id', $cliniqueId)
            : $this->orderSettings()->where('clinique_id', $cliniqueId)->first();

        return $setting instanceof ProductOrderSetting ? $setting : null;
    }

    /** URL publique de la photo de la vitrine (nom de fichier imprévisible), ou null */
    public function photoUrl(): ?string
    {
        return $this->photo_chemin ? route('produit.photo', ['fichier' => basename($this->photo_chemin)]) : null;
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

    /** Le stock de l'article dans une clinique ; null si elle ne le tient pas (28.10.2026). */
    public function stockDans(int $cliniqueId): ?int
    {
        $charge = $this->relationLoaded('stocks')
            ? $this->stocks
            : $this->stocks()->withoutGlobalScope('clinique')->get();
        $lignes = $charge->where('clinique_id', $cliniqueId);

        return $lignes->isEmpty() ? null : (int) $lignes->sum('quantite');
    }

    /**
     * Stock bas par clinique (décision de Jim, 28.10.2026) : le stock de la
     * clinique face au seuil de l'article ; une clinique qui ne tient pas
     * l'article n'est jamais sous le seuil.
     */
    public function estSousLeSeuilDans(int $cliniqueId): bool
    {
        $stock = $this->stockDans($cliniqueId);

        return $this->seuil_alerte > 0 && $stock !== null && $stock <= $this->seuil_alerte;
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
            // Un antibiotique de la liste de l'OSAV part de la consultation, où il se déclare au SI ABV (08.11.2026)
            $this->categorie === CategorieProduit::Medicament && (bool) $this->isabvPreparation()?->must_transfer => $this->nom.' : antibiotique, à délivrer depuis la facture de la consultation (il se déclare au SI ABV).',
            default => null,
        };
    }

    /** Prix vitrine, promotion déduite — le prix vendu en boutique. */
    public function prixEffectif(): float
    {
        return round((float) $this->prix * (1 - $this->promo_pct / 100), 2);
    }

    /** Les principes actifs lus d'avance (withCatalogueSubstances), ou null */
    private ?array $catalogueSubstancesCache = null;

    /**
     * Les principes actifs du catalogue Swissmedic par le code-barres de
     * l'article (03.11.2026), tels quels (meloxicamum…) ; sans code-barres
     * connu du catalogue, aucun.
     *
     * @return list<string>
     */
    public function catalogueSubstances(): array
    {
        if ($this->catalogueSubstancesCache === null) {
            $gtin = AtcFromCatalogue::clean($this->ean);
            $this->catalogueSubstancesCache = $gtin === '' ? [] : (AtcFromCatalogue::substancesForGtins([$gtin])[$gtin] ?? []);
        }

        return $this->catalogueSubstancesCache;
    }

    /** La préparation SI ABV lue d'avance (withIsabvPreparations) : false tant qu'elle n'est pas lue */
    private IsabvPreparation|false|null $isabvPreparationCache = false;

    /** La préparation SI ABV de l'article : choisie à la main, sinon par le code-barres (08.11.2026) */
    public function isabvPreparation(): ?IsabvPreparation
    {
        if ($this->isabvPreparationCache === false) {
            $this->isabvPreparationCache = app(PreparationResolver::class)->forProduct($this);
        }

        return $this->isabvPreparationCache;
    }

    /**
     * Lit d'avance, en deux requêtes, les préparations SI ABV d'une liste d'articles.
     *
     * @param  iterable<Produit|null>  $products
     */
    public static function withIsabvPreparations(iterable $products): void
    {
        $list = collect($products)->filter();
        $found = app(PreparationResolver::class)->forProducts($list);
        foreach ($list as $product) {
            $product->isabvPreparationCache = $found[$product->id] ?? null;
        }
    }

    /**
     * Lit d'avance, en une requête, les principes actifs d'une liste d'articles.
     *
     * @param  iterable<Produit|null>  $products
     */
    public static function withCatalogueSubstances(iterable $products): void
    {
        $list = collect($products)->filter();
        $byGtin = AtcFromCatalogue::substancesForGtins($list->map(fn (Produit $product): string => AtcFromCatalogue::clean($product->ean)));
        foreach ($list as $product) {
            $product->catalogueSubstancesCache = $byGtin[AtcFromCatalogue::clean($product->ean)] ?? [];
        }
    }
}
