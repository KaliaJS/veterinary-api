<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\MotifAjustementStock;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Toute mutation de stock passe ici : la quantité (par produit ×
 * clinique) bouge sous verrou et chaque geste laisse une ligne au
 * journal `mouvements_stock` — jamais de décrément silencieux
 * (docs/data-model.md §14). Les écritures sont bornées à la clinique du
 * jeton ; les sorties sont bornées à zéro et le mouvement trace l'écart
 * réellement appliqué. Un undo est un mouvement inverse.
 */
class GestionnaireStock
{
    /**
     * Applique un écart signé au stock de la clinique du contexte et le
     * journalise. Retourne l'écart réellement appliqué (0 si le stock
     * était déjà à zéro pour une sortie).
     */
    public function appliquer(
        Produit $produit,
        int $ecart,
        string $libelle,
        ?User $auteur = null,
        ?MotifAjustementStock $motif = null,
        ?Model $source = null,
    ): int {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();

        return DB::transaction(function () use ($produit, $ecart, $libelle, $auteur, $motif, $source, $cliniqueId): int {
            $stock = Stock::query()
                ->where('produit_id', $produit->id)
                ->where('clinique_id', $cliniqueId)
                ->lockForUpdate()
                ->first();

            $stock ??= Stock::create([
                'produit_id' => $produit->id,
                'clinique_id' => $cliniqueId,
                'quantite' => 0,
            ]);

            $nouvelle = max(0, $stock->quantite + $ecart);
            $reel = $nouvelle - $stock->quantite;

            if ($reel === 0) {
                return 0;
            }

            $stock->update(['quantite' => $nouvelle]);

            MouvementStock::create([
                'produit_id' => $produit->id,
                'clinique_id' => $cliniqueId,
                'user_id' => $auteur?->id,
                'quantite' => $reel,
                'libelle' => $libelle,
                'motif' => $motif,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_at' => now(),
            ]);

            return $reel;
        });
    }

    public function sortir(Produit $produit, int $quantite, string $libelle, ?User $auteur = null, ?Model $source = null): int
    {
        return $this->appliquer($produit, -abs($quantite), $libelle, $auteur, source: $source);
    }

    public function entrer(Produit $produit, int $quantite, string $libelle, ?User $auteur = null, ?Model $source = null): int
    {
        return $this->appliquer($produit, abs($quantite), $libelle, $auteur, source: $source);
    }

    /**
     * Annule les mouvements qu'un acte (facture, ordonnance) a produits :
     * un mouvement inverse par ligne du journal — l'historique reste
     * complet, le stock revient où il était.
     */
    public function inverserMouvementsDe(Model $source, string $libelle, ?User $auteur = null): void
    {
        $mouvements = MouvementStock::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get();

        foreach ($mouvements as $mouvement) {
            $this->appliquer($mouvement->produit, -$mouvement->quantite, $libelle, $auteur);
        }
    }
}
