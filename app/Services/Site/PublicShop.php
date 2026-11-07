<?php

namespace App\Services\Site;

use App\Enums\CategorieProduit;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * La boutique sur le site web public d'une organisation (choix B de Jim,
 * 03.11.2026). Servie sans compte, donc bornée au strict nécessaire :
 * - seules les cliniques ouvertes de CETTE organisation qui ont coché le
 *   service « Boutique » ;
 * - seuls ses articles « Vendu en boutique », jamais un médicament sur
 *   ordonnance ni un stupéfiant (la loi sur les produits thérapeutiques en
 *   interdit la publicité au public) ;
 * - ni prix d'achat, ni marge, ni référence, ni fournisseur ;
 * - 24 articles par page, une famille filtrée au serveur (07.11.2026) ;
 * - le stock de chaque clinique en mots (« En stock », « Plus que
 *   quelques-uns », « Épuisé »), jamais en quantités : le stock exact
 *   d'une clinique ne regarde qu'elle (23.10.2026).
 */
class PublicShop
{
    /** Jusqu'à ce nombre de pièces, « Plus que quelques-uns » (le seuil de la boutique de Mon espace). */
    public const int FEW_UP_TO = 3;

    /** @return Collection<int, Clinique> */
    public function clinics(Organisation $organisation): Collection
    {
        return Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn (Builder $societe) => $societe->where('organisation_id', $organisation->id))
            ->ouvertes()
            ->avecService(ServiceClinique::Boutique)
            ->orderBy('id')
            ->get();
    }

    /**
     * Les articles montrés au public, mis en avant d'abord, puis par nom.
     *
     * @return Builder<Produit>
     */
    public function articlesQuery(Organisation $organisation): Builder
    {
        return Produit::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->where('est_boutique', true)
            ->where('est_sur_ordonnance', false)
            ->where('est_stupefiant', false);
    }

    /** Le site montre sa page « Boutique » : une clinique l'a cochée et au moins un article est en vente. */
    public function isOpen(Organisation $organisation): bool
    {
        return $this->clinics($organisation)->isNotEmpty() && $this->articlesQuery($organisation)->exists();
    }

    /**
     * Les familles qui ont un article en vente, dans l'ordre du catalogue :
     * toujours celles de toute la boutique, quel que soit le filtre.
     *
     * @return list<array{code: string, label: string}>
     */
    public function families(Organisation $organisation): array
    {
        $presentes = $this->articlesQuery($organisation)->distinct()->pluck('categorie')
            ->map(fn (mixed $famille): string => $famille instanceof CategorieProduit ? $famille->value : (string) $famille);

        return collect(CategorieProduit::cases())
            ->filter(fn (CategorieProduit $famille): bool => $presentes->contains($famille->value))
            ->map(fn (CategorieProduit $famille): array => ['code' => $famille->value, 'label' => $famille->label()])
            ->values()->all();
    }

    /**
     * Une page de la vitrine (mis en avant d'abord, puis par nom), une
     * famille seulement si elle est donnée ; null si le site n'a pas de
     * boutique (aucune clinique ne l'a cochée).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>|null
     */
    public function page(Organisation $organisation, ?CategorieProduit $family, int $perPage): ?LengthAwarePaginator
    {
        $clinics = $this->clinics($organisation);
        if ($clinics->isEmpty()) {
            return null;
        }

        $page = $this->articlesQuery($organisation)
            ->when($family, fn (Builder $requete, CategorieProduit $famille) => $requete->where('categorie', $famille))
            ->orderByDesc('est_vedette')->orderBy('nom')->orderBy('id')
            ->paginate($perPage);
        $stocks = $this->stocks(array_map(fn (Produit $produit): int => $produit->id, $page->items()), $clinics);

        return $page->through(fn (Produit $produit): array => $this->present($produit, $clinics, $stocks));
    }

    /**
     * Un article seul (« Réserver » ou une adresse partagée qui vise un
     * article pas encore chargé), ou null s'il n'est pas dans cette vitrine.
     *
     * @return array<string, mixed>|null
     */
    public function article(Organisation $organisation, int $id): ?array
    {
        $clinics = $this->clinics($organisation);
        $produit = $clinics->isEmpty() ? null : $this->articlesQuery($organisation)->whereKey($id)->first();

        return $produit ? $this->present($produit, $clinics, $this->stocks([$produit->id], $clinics)) : null;
    }

    /**
     * @param  list<int>  $produitIds
     * @param  Collection<int, Clinique>  $clinics
     * @return \Illuminate\Support\Collection<int|string, Collection<int|string, Stock>>
     */
    private function stocks(array $produitIds, Collection $clinics): \Illuminate\Support\Collection
    {
        return Stock::withoutGlobalScopes()
            ->whereIn('produit_id', $produitIds)
            ->whereIn('clinique_id', $clinics->modelKeys())
            ->get()
            ->groupBy('produit_id')
            ->map(fn ($lignes) => $lignes->keyBy('clinique_id'));
    }

    /**
     * Ce que le public voit d'un article : jamais le prix d'achat, la marge,
     * la référence ni le fournisseur ; le stock de chaque clinique en mots.
     *
     * @param  Collection<int, Clinique>  $clinics
     * @param  \Illuminate\Support\Collection<int|string, Collection<int|string, Stock>>  $stocks
     * @return array<string, mixed>
     */
    private function present(Produit $produit, Collection $clinics, \Illuminate\Support\Collection $stocks): array
    {
        $parClinique = $stocks[$produit->id] ?? collect();

        return [
            'id' => $produit->id,
            'name' => $produit->nom,
            'family' => $produit->categorie->value,
            'description' => $produit->shop_description,
            'photo_url' => $produit->photoUrl(),
            // Le prix vendu, promotion déduite, et celui d'avant la promotion
            'price' => $produit->prixEffectif(),
            'base_price' => (float) $produit->prix,
            'promo_pct' => (int) $produit->promo_pct,
            'is_featured' => (bool) $produit->est_vedette,
            'stocks' => $clinics->map(fn (Clinique $clinique): array => [
                'clinic_id' => $clinique->id,
                'name' => $clinique->nom,
                'city' => $clinique->ville,
                ...$this->level((int) ($parClinique[$clinique->id]->quantite ?? 0)),
            ])->values()->all(),
        ];
    }

    /** @return array{level: string, label: string} */
    private function level(int $quantite): array
    {
        return match (true) {
            $quantite <= 0 => ['level' => 'out', 'label' => 'Épuisé'],
            $quantite <= self::FEW_UP_TO => ['level' => 'few', 'label' => 'Plus que quelques-uns'],
            default => ['level' => 'in_stock', 'label' => 'En stock'],
        };
    }
}
