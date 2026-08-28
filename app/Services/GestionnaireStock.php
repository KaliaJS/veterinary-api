<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\MotifAjustementStock;
use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Toute mutation de stock passe ici : la quantité (par produit ×
 * clinique) bouge sous verrou et chaque geste laisse une ligne au
 * journal `mouvements_stock` — jamais de décrément silencieux
 * (docs/data-model.md §14). Les écritures visent la clinique du jeton
 * par défaut, ou explicitement une clinique SŒUR de l'organisation
 * (l'écran Stocks règle la ventilation de toutes les cliniques) —
 * jamais au-delà : la cible est contrôlée ici, et les lignes sont
 * créées hors événements du trait clinique (dérogation documentée à
 * l'invariant d'écriture locale, comme l'embauche multi-cliniques).
 * Les sorties sont bornées à zéro et le mouvement trace l'écart
 * réellement appliqué. Un undo est un mouvement inverse.
 */
class GestionnaireStock
{
    /**
     * Applique un écart signé au stock d'une clinique (celle du contexte
     * par défaut) et le journalise. Retourne l'écart réellement appliqué
     * (0 si le stock était déjà à zéro pour une sortie).
     *
     * @throws AuthorizationException si la clinique visée n'est pas de l'organisation du jeton
     */
    public function appliquer(
        Produit $produit,
        int $ecart,
        string $libelle,
        ?User $auteur = null,
        ?MotifAjustementStock $motif = null,
        ?Model $source = null,
        ?int $cliniqueId = null,
    ): int {
        $cliniqueId = $this->cliniqueCible($cliniqueId);

        return DB::transaction(function () use ($produit, $ecart, $libelle, $auteur, $motif, $source, $cliniqueId): int {
            $stock = Stock::query()
                ->withoutGlobalScope('clinique')
                ->where('produit_id', $produit->id)
                ->where('clinique_id', $cliniqueId)
                ->lockForUpdate()
                ->first();

            $stock ??= Stock::withoutEvents(fn (): Stock => Stock::create([
                'produit_id' => $produit->id,
                'clinique_id' => $cliniqueId,
                'quantite' => 0,
            ]));

            $nouvelle = max(0, $stock->quantite + $ecart);
            $reel = $nouvelle - $stock->quantite;

            if ($reel === 0) {
                return 0;
            }

            $stock->update(['quantite' => $nouvelle]);

            MouvementStock::withoutEvents(fn (): MouvementStock => MouvementStock::create([
                'produit_id' => $produit->id,
                'clinique_id' => $cliniqueId,
                'user_id' => $auteur?->id,
                'quantite' => $reel,
                'libelle' => $libelle,
                'motif' => $motif,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_at' => now(),
            ]));

            return $reel;
        });
    }

    public function sortir(Produit $produit, int $quantite, string $libelle, ?User $auteur = null, ?Model $source = null, ?int $cliniqueId = null): int
    {
        return $this->appliquer($produit, -abs($quantite), $libelle, $auteur, source: $source, cliniqueId: $cliniqueId);
    }

    public function entrer(Produit $produit, int $quantite, string $libelle, ?User $auteur = null, ?Model $source = null, ?int $cliniqueId = null): int
    {
        return $this->appliquer($produit, abs($quantite), $libelle, $auteur, source: $source, cliniqueId: $cliniqueId);
    }

    /**
     * Clinique visée par l'écriture : celle du jeton sans choix ; une
     * autre clinique n'est acceptée que dans l'organisation du jeton.
     * Hors contexte (console), la clinique doit être explicite.
     *
     * @throws AuthorizationException
     */
    private function cliniqueCible(?int $cliniqueId): int
    {
        $contexte = app(ContexteClinique::class);

        if ($cliniqueId === null || $cliniqueId === $contexte->cliniqueId()) {
            return $cliniqueId ?? $contexte->cliniqueId()
                ?? throw new AuthorizationException('Aucune clinique pour cette écriture de stock.');
        }

        if (! $contexte->estDefini()) {
            return $cliniqueId;
        }

        $estSoeur = Clinique::query()
            ->whereKey($cliniqueId)
            ->whereRelation('societe', 'organisation_id', $contexte->organisationId())
            ->exists();

        return $estSoeur
            ? $cliniqueId
            : throw new AuthorizationException('Écriture de stock hors de l’organisation du jeton.');
    }

    /**
     * Annule les mouvements qu'un acte (facture, ordonnance) a produits :
     * un mouvement inverse par ligne du journal — l'historique reste
     * complet, le stock revient où il était.
     */
    public function inverserMouvementsDe(Model $source, string $libelle, ?User $auteur = null): void
    {
        $mouvements = MouvementStock::query()
            ->withoutGlobalScope('clinique')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get();

        foreach ($mouvements as $mouvement) {
            $this->appliquer($mouvement->produit, -$mouvement->quantite, $libelle, $auteur, cliniqueId: $mouvement->clinique_id);
        }
    }
}
