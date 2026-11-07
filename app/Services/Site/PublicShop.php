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

/**
 * La boutique sur le site web public d'une organisation (choix B de Jim,
 * 03.11.2026). Servie sans compte, donc bornée au strict nécessaire :
 * - seules les cliniques ouvertes de CETTE organisation qui ont coché le
 *   service « Boutique » ;
 * - seuls ses articles « Vendu en boutique », jamais un médicament sur
 *   ordonnance ni un stupéfiant (la loi sur les produits thérapeutiques en
 *   interdit la publicité au public) ;
 * - ni prix d'achat, ni marge, ni référence, ni fournisseur ;
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
     * La vitrine servie au site, ou null s'il n'a pas de boutique.
     *
     * @return array{families: list<array{code: string, label: string}>, clinics: list<array<string, mixed>>, articles: list<array<string, mixed>>}|null
     */
    public function showcase(Organisation $organisation): ?array
    {
        $clinics = $this->clinics($organisation);
        if ($clinics->isEmpty()) {
            return null;
        }

        $articles = $this->articlesQuery($organisation)->orderByDesc('est_vedette')->orderBy('nom')->orderBy('id')->get();
        $stocks = Stock::withoutGlobalScopes()
            ->whereIn('produit_id', $articles->modelKeys())
            ->whereIn('clinique_id', $clinics->modelKeys())
            ->get()
            ->groupBy('produit_id');

        $presentes = $articles->map(fn (Produit $produit): string => $produit->categorie->value)->unique();

        return [
            // Dans l'ordre du catalogue, seulement celles qui ont un article en vente
            'families' => collect(CategorieProduit::cases())
                ->filter(fn (CategorieProduit $famille): bool => $presentes->contains($famille->value))
                ->map(fn (CategorieProduit $famille): array => ['code' => $famille->value, 'label' => $famille->label()])
                ->values()->all(),
            'clinics' => $clinics->map(fn (Clinique $clinique): array => ['id' => $clinique->id, 'name' => $clinique->nom, 'city' => $clinique->ville])->values()->all(),
            'articles' => $articles->map(function (Produit $produit) use ($clinics, $stocks): array {
                $parClinique = ($stocks[$produit->id] ?? collect())->keyBy('clinique_id');

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
                        ...$this->level((int) ($parClinique[$clinique->id]->quantite ?? 0)),
                    ])->values()->all(),
                ];
            })->values()->all(),
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
