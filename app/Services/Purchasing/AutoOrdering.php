<?php

namespace App\Services\Purchasing;

use App\Auth\SousContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ProductOrderSetting;
use App\Models\ProductSource;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\Supplier;
use App\Services\Numeroteur;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La commande automatique (décisions de Jim, 03.11.2026) : un article réglé
 * « Commander automatiquement sous le seuil » qui passe sous son seuil dans
 * une clinique, après n'importe quelle sortie de stock et une fois la
 * transaction validée, entre dans le panier automatique de sa première
 * source dans cette clinique. Ce panier est un brouillon marqué automatique,
 * un par fournisseur et par clinique, jamais en double (sous le verrou du
 * fournisseur). Sans source, l'article reste à commander à la main.
 */
class AutoOrdering
{
    public function __construct(
        private Numeroteur $numeroteur,
        private SousContexteClinique $sousContexte,
        private PurchasingConnectors $connectors,
    ) {}

    /**
     * Appelé à chaque changement de stock (Stock::saved) : la suite attend la
     * validation de la transaction ; une transaction annulée ne commande rien.
     */
    public static function afterStockChange(Stock $stock, int $before): void
    {
        $after = (int) $stock->quantite;
        if ($after === $before) {
            return;
        }
        $produitId = (int) $stock->produit_id;
        $cliniqueId = (int) $stock->clinique_id;

        DB::afterCommit(function () use ($produitId, $cliniqueId, $after, $before): void {
            // Une panne ici ne défait jamais la sortie de stock déjà validée
            rescue(fn () => app(self::class)->consider($produitId, $cliniqueId, $after < $before, $after > $before));
        });
    }

    /**
     * L'article dans la clinique : sous son seuil et réglé automatique, il
     * entre au panier de sa première source ; remonté au-dessus de son seuil,
     * il n'est plus « à commander à la main ».
     */
    public function consider(int $produitId, int $cliniqueId, bool $isExit = true, bool $isEntry = false): void
    {
        $produit = Produit::withoutGlobalScopes()->find($produitId);
        $clinique = Clinique::withoutGlobalScopes()->find($cliniqueId);
        if ($produit === null || $clinique === null) {
            return;
        }
        $produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScopes()]);

        $stock = Stock::withoutGlobalScopes()->where('produit_id', $produitId)->where('clinique_id', $cliniqueId);
        // Une entrée (réception, ajustement) : l'article indisponible sans autre source redevient automatique
        if ($isEntry || ! $produit->estSousLeSeuilDans($cliniqueId)) {
            (clone $stock)->whereNotNull('manual_order_since')->update(['manual_order_since' => null, 'manual_order_reason' => null]);
        }
        // Au-dessus du seuil : l'écart décidé par la clinique tombe, et l'article sort
        // d'un panier automatique pas encore parti, sans écart (04.11.2026)
        if (! $produit->estSousLeSeuilDans($cliniqueId)) {
            ProductOrderSetting::query()->where('produit_id', $produitId)->where('clinique_id', $cliniqueId)->whereNotNull('excluded_at')
                ->update(['excluded_at' => null, 'excluded_reason' => null, 'excluded_by' => null]);
            $this->leaveOpenBaskets($produitId, $cliniqueId);

            return;
        }
        // Réglé automatique dans cette clinique, pas écarté par elle, pas en attente d'une commande à la main
        $setting = $produit->orderSettingIn($cliniqueId);
        if (! $isExit || $produit->est_stupefiant || $setting === null || ! $setting->isActive() || (clone $stock)->whereNotNull('manual_order_since')->exists()) {
            return;
        }

        $this->sousContexte->pour($clinique, function () use ($produit, $clinique): void {
            $source = $produit->sourcesIn($clinique->id)->first();
            if ($source === null) {
                $this->markManual($produit, $clinique->id, 'Aucune source pour la commande automatique');

                return;
            }
            $this->addToBasket($produit, $clinique, $source);
        });
    }

    /**
     * L'article dans le panier automatique du fournisseur de la source, créé
     * s'il n'existe pas. Rien si l'article est déjà sur une commande ouverte
     * de la clinique. Rend la ligne ajoutée.
     *
     * @param  array{stock?: int, threshold?: int, at?: mixed, manual?: bool}|null  $reason  pourquoi il est là (par défaut : maintenant) ; `manual` : une ligne posée par une personne
     */
    public function addToBasket(Produit $produit, Clinique $clinique, ProductSource $source, ?array $reason = null, ?string $movedFrom = null, ?int $quantity = null): ?CommandeFournisseurLigne
    {
        return $this->sousContexte->pour($clinique, fn () => DB::transaction(function () use ($produit, $clinique, $source, $reason, $movedFrom, $quantity): ?CommandeFournisseurLigne {
            // Un panier par fournisseur et par clinique : les ajouts au même panier passent l'un après l'autre
            $supplier = Supplier::withoutGlobalScopes()->whereKey($source->supplier_id)->lockForUpdate()->first();
            if ($supplier === null || (int) $supplier->clinique_id !== $clinique->id) {
                return null;
            }
            if (self::isOnOpenOrder($produit->id, $clinique->id)) {
                return null;
            }
            $stock = (int) $produit->stockDans($clinique->id);
            $basket = $this->basketOf($supplier);
            if ($basket === null) {
                return null;
            }

            return $basket->lignes()->create([
                'produit_id' => $produit->id,
                'quantite' => $quantity ?? self::quantityFor($produit, $stock, $produit->orderSettingIn($clinique->id)?->reorder_up_to),
                'cout' => $source->cost ?? $produit->cout,
                // Une ligne manuelle déplacée d'un autre panier garde ses raisons vides
                'reason_stock' => ($reason['manual'] ?? false) ? null : ($reason['stock'] ?? $stock),
                'reason_threshold' => ($reason['manual'] ?? false) ? null : ($reason['threshold'] ?? (int) $produit->seuil_alerte),
                'reason_at' => ($reason['manual'] ?? false) ? null : ($reason['at'] ?? now()),
                'moved_from' => $movedFrom,
            ]);
        }));
    }

    /**
     * Ce que le panier commande : de quoi remonter jusqu'à la quantité réglée,
     * sinon la règle de « Préparer les bons » (deux fois le seuil, au moins le seuil).
     */
    public static function quantityFor(Produit $produit, int $stock, ?int $target = null): int
    {
        return $target !== null && $target > 0
            ? max($target - $stock, 1)
            : max((int) $produit->seuil_alerte * 2 - $stock, (int) $produit->seuil_alerte, 1);
    }

    /** L'article est-il déjà sur une commande ouverte (brouillon ou envoyée) de la clinique ? */
    public static function isOnOpenOrder(int $produitId, int $cliniqueId): bool
    {
        return CommandeFournisseurLigne::query()
            ->where('produit_id', $produitId)
            ->whereHas('commande', fn ($commande) => $commande->withoutGlobalScopes()
                ->where('clinique_id', $cliniqueId)
                ->where('statut', '!=', StatutCommandeFournisseur::Receptionnee)
                // Le brouillon d'un fournisseur retiré ne retient plus l'article (04.11.2026)
                ->whereNull('supplier_removed_at'))
            ->exists();
    }

    /**
     * Écarte l'article de la commande automatique dans la clinique (article
     * retiré d'un panier automatique, panier supprimé), jusqu'à ce que son
     * stock repasse au-dessus du seuil ; la raison et qui l'a fait sont gardées.
     */
    public static function exclude(int $produitId, int $cliniqueId, string $reason, ?int $userId): void
    {
        // Pas sous son seuil au moment du retrait : la ligne part, sans écart (deuxième vérification, 04.11.2026)
        $produit = Produit::withoutGlobalScopes()->with(['stocks' => fn ($requete) => $requete->withoutGlobalScopes()])->find($produitId);
        if ($produit === null || ! $produit->estSousLeSeuilDans($cliniqueId)) {
            return;
        }
        ProductOrderSetting::query()->updateOrCreate(
            ['produit_id' => $produitId, 'clinique_id' => $cliniqueId],
            ['excluded_at' => now(), 'excluded_reason' => mb_substr($reason, 0, 255), 'excluded_by' => $userId],
        );
    }

    /**
     * L'article remonté au-dessus de son seuil sort des paniers automatiques
     * de la clinique pas encore partis ; un panier qui n'a plus rien s'efface.
     * Seules les lignes posées par la commande automatique partent (elles
     * gardent pourquoi elles sont là, `reason_at`) : une ligne ajoutée à la main
     * reste. Un envoi resté sans réponse chez sa centrale se lit sur le panier
     * verrouillé, comme pour l'envoi : il n'est jamais touché (troisième
     * vérification, 04.11.2026).
     */
    private function leaveOpenBaskets(int $produitId, int $cliniqueId): void
    {
        $ids = CommandeFournisseur::withoutGlobalScopes()
            ->where('clinique_id', $cliniqueId)
            ->where('is_auto', true)
            ->where('statut', StatutCommandeFournisseur::Brouillon)
            ->whereHas('lignes', fn ($ligne) => $ligne->where('produit_id', $produitId)->whereNotNull('reason_at'))
            ->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $produitId): void {
                $locked = CommandeFournisseur::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
                if ($locked === null || $locked->statut !== StatutCommandeFournisseur::Brouillon) {
                    return;
                }
                $supplier = Supplier::withoutGlobalScopes()->find($locked->supplier_id);
                $connector = $supplier?->channel === 'api' ? $this->connectors->get($supplier->connector) : null;
                if ($connector?->pendingSend($locked) !== null) {
                    return;
                }
                $locked->lignes()->where('produit_id', $produitId)->whereNotNull('reason_at')->delete();
                if ($locked->lignes()->doesntExist()) {
                    $locked->delete();
                }
            });
        }
    }

    /**
     * Le panier automatique du fournisseur, sous son verrou ; créé au besoin,
     * avec sa date butoir. Un panier en cours d'envoi par une centrale ne
     * reçoit plus rien : null.
     */
    private function basketOf(Supplier $supplier): ?CommandeFournisseur
    {
        // Sous verrou, comme l'envoi : un envoi qui part au même instant se lit à jour (04.11.2026)
        $basket = CommandeFournisseur::withoutGlobalScopes()
            ->where('clinique_id', $supplier->clinique_id)
            ->where('supplier_id', $supplier->id)
            ->where('is_auto', true)
            ->where('statut', StatutCommandeFournisseur::Brouillon)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($basket !== null) {
            $connector = $supplier->channel === 'api' ? $this->connectors->get($supplier->connector) : null;

            return $connector?->pendingSend($basket) !== null ? null : $basket;
        }

        return CommandeFournisseur::create([
            'clinique_id' => $supplier->clinique_id,
            'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeFournisseur),
            'fournisseur' => $supplier->name,
            'statut' => StatutCommandeFournisseur::Brouillon,
            'supplier_id' => $supplier->id,
            'is_auto' => true,
            'deadline_at' => $supplier->nextDeadline(CarbonImmutable::now()),
        ]);
    }

    /** À commander à la main dans la clinique, avec la raison (l'alerte le dit). */
    public function markManual(Produit $produit, int $cliniqueId, string $reason): void
    {
        Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $cliniqueId)
            ->update(['manual_order_since' => now(), 'manual_order_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * Les articles automatiques sous leur seuil, sans commande ouverte, dans
     * chaque clinique qui les tient : un filet pour un changement de stock
     * passé hors des événements (le passage planifié les reprend).
     */
    public function sweep(): void
    {
        $actives = ProductOrderSetting::query()->where('is_auto_order', true)->whereNull('excluded_at')->get(['produit_id', 'clinique_id'])
            ->map(fn (ProductOrderSetting $s): string => $s->produit_id.'-'.$s->clinique_id)->flip();
        if ($actives->isEmpty()) {
            return;
        }
        Stock::withoutGlobalScopes()
            ->whereIn('produit_id', ProductOrderSetting::query()->where('is_auto_order', true)->whereNull('excluded_at')->select('produit_id'))
            ->whereHas('produit', fn ($produit) => $produit->withoutGlobalScopes()->where('est_stupefiant', false))
            ->with(['produit' => fn ($produit) => $produit->withoutGlobalScopes()])
            ->get()
            ->filter(fn (Stock $stock): bool => $actives->has($stock->produit_id.'-'.$stock->clinique_id))
            ->filter(fn (Stock $stock): bool => $stock->produit !== null && (int) $stock->quantite <= (int) $stock->produit->seuil_alerte && $stock->produit->seuil_alerte > 0)
            ->reject(fn (Stock $stock): bool => self::isOnOpenOrder((int) $stock->produit_id, (int) $stock->clinique_id) || $stock->manual_order_since !== null)
            ->each(fn (Stock $stock) => rescue(fn () => $this->consider((int) $stock->produit_id, (int) $stock->clinique_id)));
    }
}
