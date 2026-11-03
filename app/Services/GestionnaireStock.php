<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\MotifAjustementStock;
use App\Models\Clinique;
use App\Models\MouvementStock;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /** Le compte d'un flacon entamé, et le compte qui le dit vide (huitième vérification) */
    private const string COUNTED = 'Inventaire du flacon entamé';

    private const string COUNTED_EMPTY = 'Flacon entamé compté vide';

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
        // Un stupéfiant ne bouge que par son registre (24.10.2026, décision de
        // Jim) : facture, ordonnance, soin, vente ou commande n'y touchent pas,
        // le registre et le stock ne divergent jamais
        if ($produit->est_stupefiant && ! $source instanceof MouvementStupefiant) {
            return 0;
        }

        $cliniqueId = $this->cliniqueCible($cliniqueId);

        return DB::transaction(function () use ($produit, $ecart, $libelle, $auteur, $motif, $source, $cliniqueId): int {
            // Le même ordre partout, l'article puis le stock (vérification
            // finale de l'hospitalisation, 03.11.2026) : sous MySQL, l'écriture
            // au journal verrouille l'article ; la caisse, les réceptions et
            // les ordonnances ne butent ainsi jamais contre une prise
            $this->lockedProduct($produit);
            $stock = $this->lockedStock($produit, $cliniqueId);

            $nouvelle = max(0, $stock->quantite + $ecart);
            $reel = $nouvelle - $stock->quantite;

            if ($reel === 0) {
                return 0;
            }

            // Le flacon entamé n'est pas un flacon fermé : il reste, même au
            // dernier flacon fermé sorti (troisième vérification, 03.11.2026)
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

    /**
     * Sort une dose, en fraction d'unité (médicaments à la dose, décision de
     * Jim du 03.11.2026) : d'abord dans l'unité entamée de la clinique, et
     * une nouvelle unité ne s'ouvre que quand elle est vide. Rupture
     * seulement quand les unités fermées et le reste entamé ne suffisent
     * pas. Un stupéfiant ne sort que par son registre ; une contenance
     * inconnue ne sort rien (null).
     *
     * Sixième vérification : le journal garde de quoi rendre la sortie à
     * l'identique (unités ouvertes, flacon d'avant et d'après, ce qui a été
     * pris dans le flacon d'avant, unité de dose). Une dose plus grande que
     * le stock disponible est refusée, sauf la dose prescrite elle-même
     * (rupture partielle, le soin reste noté).
     *
     * @return array{units: int, dose: float, rupture: bool, registre: bool, opening: int|null, content: float|null, dose_unit: string|null, price: mixed}|null
     */
    public function takeDose(Produit $produit, float $dose, string $label, ?User $author = null, ?Model $source = null, ?int $cliniqueId = null, ?float $prescribed = null): ?array
    {
        if ($produit->est_stupefiant) {
            return ['units' => 0, 'dose' => 0.0, 'rupture' => false, 'registre' => true, 'opening' => null, 'content' => null, 'dose_unit' => null, 'price' => null];
        }
        if ((float) $produit->content_per_unit <= 0 || $dose <= 0) {
            return null;
        }
        $cliniqueId = $this->cliniqueCible($cliniqueId);

        return DB::transaction(function () use ($produit, $dose, $label, $author, $source, $cliniqueId, $prescribed): ?array {
            // L'article d'abord, puis son stock, comme partout (dixième vérification) : contenance,
            // unité et prix lus sous le verrou, ceux qui servent vraiment et que la prise fige
            $produit = $this->lockedProduct($produit);
            $stock = $this->lockedStock($produit, $cliniqueId);
            $content = (float) $produit->content_per_unit;
            if ($content <= 0) {
                return null;
            }

            $rest = (float) $stock->opened_rest;
            $total = round($rest + $stock->quantite * $content, 3);
            if (round($dose, 3) > $total && $total > 0 && ($prescribed === null || round($dose, 3) > round($prescribed, 3))) {
                throw ValidationException::withMessages(['dose_amount' => 'Cette dose ('.self::formatDose($dose).' '.$produit->dose_unit.') dépasse le stock disponible ('.self::formatDose($total).' '.$produit->dose_unit.') : vérifiez la dose.']);
            }
            $missing = round($dose - $rest, 3);
            $units = $missing > 0 ? min($stock->quantite, (int) ceil(round($missing / $content, 6))) : 0;
            $available = $rest + $units * $content;
            $taken = round(min($dose, $available), 3);

            // Rien à sortir (stock vide) : aucune ligne vide au journal
            if ($taken <= 0) {
                return ['units' => 0, 'dose' => 0.0, 'rupture' => true, 'registre' => false, 'opening' => null, 'content' => $content, 'dose_unit' => $produit->dose_unit, 'price' => $produit->prix];
            }
            $left = round($available - $taken, 3);
            $before = ['number' => $stock->opening_number, 'at' => $stock->opened_at, 'rest' => $rest];
            $opening = $units > 0 ? $this->nextOpening($stock) : $stock->opening_number;
            $stock->update([
                'quantite' => $stock->quantite - $units,
                'opened_rest' => $left,
                'opening_number' => $opening,
                // Le flacon fini n'est plus là ; un nouveau est ouvert maintenant
                'opened_at' => $left <= 0 ? null : ($units > 0 ? now() : ($stock->opened_at ?? now())),
            ]);
            // Signée comme la quantité : une sortie est négative
            $this->journal($produit, $cliniqueId, $author, -$units, -$taken, $label, null, $source, [
                'opening_number' => $opening,
                'previous_opening_number' => $before['number'],
                'previous_opened_at' => $before['at'],
                'from_opened' => round(min($taken, $before['rest']), 3),
            ]);

            // La contenance vraiment utilisée : la prise fige celle-là
            return ['units' => $units, 'dose' => $taken, 'rupture' => $taken < round($dose, 3), 'registre' => false, 'opening' => $left > 0 ? $opening : null, 'content' => $content, 'dose_unit' => $produit->dose_unit, 'price' => $produit->prix];
        });
    }

    /**
     * Rend à l'identique ce qu'une sortie a pris (une prise annulée, sixième
     * vérification, 03.11.2026). Si aucun flacon n'a été ouvert ni jeté
     * depuis, tout revient : les unités fermées ouvertes par la prise
     * redeviennent fermées, le flacon d'avant retrouve son reste. Sinon, les
     * unités que la prise a vidées seule reviennent fermées ; ce qui était
     * dans un flacon jeté, ou fini puis remplacé, est perdu. Jamais au-delà
     * de la plus petite contenance (figée sur la prise, ou du jour) ; jamais
     * dans une autre unité de dose. Le journal dit ce qui revient et ce qui
     * est perdu.
     *
     * L'article passé est celui que lockProductAndStock a verrouillé et relu.
     *
     * @return array{dose: float, units: int, lost: bool, surplus: float, reason: string|null}
     */
    public function giveBackTake(Produit $produit, MouvementStock $take, string $label, ?User $author, ?Model $source, ?string $doseUnit, ?float $contentPerUnit): array
    {
        return DB::transaction(function () use ($produit, $take, $label, $author, $source, $doseUnit, $contentPerUnit): array {
            $cliniqueId = $take->clinique_id;
            // L'article est déjà verrouillé et relu par lockProductAndStock : la contenance du
            // moment (dixième vérification) ; reste le stock, après lui
            $stock = $this->lockedStock($produit, $cliniqueId);
            $current = (float) $produit->content_per_unit;
            $frozen = (float) ($contentPerUnit ?? $current);
            $cap = $current > 0 ? min($frozen > 0 ? $frozen : $current, $current) : 0.0;
            $dose = round(-(float) $take->dose, 3);
            $units = max(0, -$take->quantite);
            $fromOld = round((float) $take->from_opened, 3);
            $last = $units > 0 ? round($dose - $fromOld - ($units - 1) * $frozen, 3) : $dose;
            $unit = $doseUnit ?? $take->dose_unit ?? $produit->dose_unit;
            $isSameUnit = $unit === $produit->dose_unit && $cap > 0;
            $isSameFlask = $isSameUnit && $take->opening_number !== null && $stock->opening_number === $take->opening_number;
            // Compté depuis la prise (huitième vérification) : le compte dit déjà ce qu'il y a, rien n'y revient
            // Lu sur la ligne de stock verrouillée, pas dans le journal (neuvième vérification)
            $isCounted = $isSameFlask && $stock->last_count_id !== null && $stock->last_count_id > $take->id;

            $closed = 0;
            $back = 0.0;
            $rest = (float) $stock->opened_rest;
            $restore = null;
            $reason = null;
            if ($isCounted) {
                // Seules les unités fermées vidées par la seule prise reviennent
                $closed = max(0, $units - 1);
                $back = $closed * $frozen;
                $reason = 'counted';
            } elseif ($isSameFlask) {
                $refilled = round($rest + $last, 3);
                if ($units > 0 && $refilled >= $frozen - 0.0005) {
                    // Le flacon ouvert par la prise est de nouveau plein (à sa contenance) : il
                    // redevient fermé, et le flacon d'avant retrouve ce que la prise y avait pris
                    $closed = $units;
                    $restore = ['opened_rest' => min($fromOld, $cap), 'opening_number' => $take->previous_opening_number ?? $stock->opening_number, 'opened_at' => $fromOld > 0 ? ($take->previous_opened_at ?? now()) : null];
                    // Compté plus haut entre-temps (septième vérification) : l'écart ne revient pas deux fois
                    $reason = $refilled > $frozen + 0.0005 ? 'counted' : null;
                } else {
                    $closed = max(0, $units - 1);
                    $room = max(0.0, $cap - $rest);
                    $into = round(min($room, $units > 0 ? $last + $fromOld : $dose), 3);
                    $restore = ['opened_rest' => round($rest + $into, 3), 'opening_number' => $stock->opening_number, 'opened_at' => $stock->opened_at ?? ($into > 0 ? ($take->previous_opened_at ?? now()) : null)];
                }
                // Ce qui revient vraiment au stock : les unités fermées et l'écart du reste entamé
                $back = $closed * $frozen + (float) $restore['opened_rest'] - $rest;
            } else {
                // Le flacon de la prise n'est plus là (jeté, ou fini puis un autre ouvert),
                // ou l'unité de dose a changé : seules les unités vidées par la prise seule reviennent
                $closed = $take->opening_number !== null && $frozen > 0 ? max(0, $units - 1) : 0;
                $back = $closed * $frozen;
                $reason = match (true) {
                    $current <= 0 => 'content',
                    ! $isSameUnit => 'unit',
                    default => $this->goneReason($produit, $cliniqueId, $take, $stock),
                };
            }
            $back = round(max(0.0, min($back, $dose)), 3);
            $lost = round($dose - $back, 3);
            $reason ??= $lost > 0 ? 'full' : null;

            $stock->update(['quantite' => $stock->quantite + $closed, ...($restore ?? [])]);
            $take->forceFill(['reversed_at' => now()])->save();
            if ($back > 0) {
                $this->journal($produit, $cliniqueId, $author, $closed, $back, $label, null, $source, ['dose_unit' => $unit]);
            }
            if ($lost > 0) {
                $why = match ($reason) {
                    'unit' => 'l’unité de dose de l’article a changé',
                    'discarded' => 'son flacon entamé a été jeté',
                    'reset' => 'son flacon entamé a été remis à zéro',
                    'finished' => 'son flacon entamé a été fini, un autre est ouvert',
                    'counted' => 'le reste a été compté entre-temps',
                    'content' => 'la contenance de l’article a été effacée',
                    'unknown' => 'son flacon d’origine n’est pas connu (prise d’avant le suivi des flacons)',
                    default => 'surplus au delà de la contenance',
                };
                $this->journal($produit, $cliniqueId, $author, 0, null, 'Dose rendue non remise en stock, '.$why.' : '.self::formatDose($lost).' '.$unit.' perdus ('.$label.')', null, $source, ['dose_unit' => $unit]);
            }

            return ['dose' => $back, 'units' => $closed, 'lost' => $back <= 0, 'surplus' => $lost, 'reason' => $reason];
        });
    }

    /**
     * Pourquoi le flacon d'une sortie n'est plus là (septième vérification) :
     * jeté, remis à zéro (unité changée, compté vide), fini puis un autre
     * ouvert, ou inconnu (sortie d'avant le suivi des flacons). « Fini »
     * seulement si c'est vrai.
     */
    private function goneReason(Produit $produit, int $cliniqueId, MouvementStock $take, Stock $stock): string
    {
        if ($take->opening_number === null) {
            return 'unknown';
        }
        $closing = MouvementStock::query()->withoutGlobalScopes()
            ->where('produit_id', $produit->id)->where('clinique_id', $cliniqueId)
            ->where('opening_number', $take->opening_number)->where('id', '>', $take->id)
            // Ce qui ferme un flacon : jeté, remis à zéro (unité changée), compté vide ; pas un simple compte
            ->where(fn ($q) => $q->whereIn('motif', [MotifAjustementStock::Peremption->value, MotifAjustementStock::Casse->value])
                ->orWhere(fn ($q) => $q->where('motif', MotifAjustementStock::Inventaire->value)
                    ->where(fn ($q) => $q->where('libelle', self::COUNTED_EMPTY)->orWhere('libelle', 'like', 'Contenance changée%remis à zéro'))))
            ->orderBy('id')->sharedLock()->first();
        if ($closing !== null) {
            return $closing->motif === MotifAjustementStock::Inventaire ? 'reset' : 'discarded';
        }

        return $stock->opening_number > $take->opening_number ? 'finished' : 'unknown';
    }

    /**
     * Jeter l'unité entamée (périmée, souillée), avec un motif et une trace
     * au journal, même quand il ne reste aucune unité fermée (troisième
     * vérification, 03.11.2026). Rien d'entamé : rien à jeter (0).
     */
    public function discardOpened(Produit $produit, MotifAjustementStock $motif, ?User $author = null, ?int $cliniqueId = null): float
    {
        $cliniqueId = $this->cliniqueCible($cliniqueId);

        return DB::transaction(function () use ($produit, $motif, $author, $cliniqueId): float {
            // Vérifié avant : un refus ne crée aucune ligne de stock ; l'article d'abord (dixième vérification)
            $produit = $this->lockedProduct($produit);
            $stock = $this->lockedStock($produit, $cliniqueId, isCreating: false);
            $rest = round((float) $stock?->opened_rest, 3);
            if ($stock === null || $rest <= 0) {
                return 0.0;
            }
            // Le flacon jeté n'est plus là : son numéro ne revient plus (sixième vérification)
            $discarded = $stock->opening_number;
            $stock->update(['opened_rest' => 0, 'opened_at' => null, 'opening_number' => $this->nextOpening($stock)]);
            $this->journal($produit, $cliniqueId, $author, 0, -$rest, 'Unité entamée jetée', $motif, null, ['opening_number' => $discarded]);

            return $rest;
        });
    }

    /**
     * L'inventaire du flacon entamé (« 1 entamé, 12 ml ») : le reste compté
     * remplace le reste noté, l'écart part au journal (quatrième
     * vérification, 03.11.2026). Retourne l'écart.
     */
    public function countOpened(Produit $produit, float $rest, ?User $author = null, ?int $cliniqueId = null, ?string $doseUnit = null): float
    {
        $cliniqueId = $this->cliniqueCible($cliniqueId);

        return DB::transaction(function () use ($produit, $rest, $author, $cliniqueId, $doseUnit): float {
            // L'article d'abord, sous son verrou : la contenance et l'unité vérifiées là (dixième vérification)
            $produit = $this->lockedProduct($produit);
            $content = (float) $produit->content_per_unit;
            if ($content <= 0 || $produit->dose_unit === null) {
                throw ValidationException::withMessages(['opened_rest' => 'La contenance de l’article n’est pas connue : renseignez-la d’abord.']);
            }
            if ($doseUnit !== null && $doseUnit !== $produit->dose_unit) {
                throw ValidationException::withMessages(['dose_unit' => 'Le reste se compte en '.$produit->dose_unit.', l’unité de l’article.']);
            }
            if ($rest > $content) {
                throw ValidationException::withMessages(['opened_rest' => 'Le reste entamé ne dépasse pas la contenance d’une unité.']);
            }
            $stock = $this->lockedStock($produit, $cliniqueId);
            $gap = round($rest - (float) $stock->opened_rest, 3);
            // Rien d'entamé et rien compté : aucun flacon à noter
            if ($gap == 0.0 && $rest <= 0) {
                return 0.0;
            }
            // Pas encore de reste : c'est une nouvelle ouverture déclarée ; compté à zéro, plus de flacon entamé
            $isDeclared = (float) $stock->opened_rest <= 0;
            $isEmptied = $rest <= 0 && ! $isDeclared;
            // Déclaré : un nouveau flacon ; compté : celui-là ; compté vide : il s'en va
            $counted = $isDeclared ? $this->nextOpening($stock) : $stock->opening_number;
            $label = match (true) {
                $isDeclared => 'Flacon entamé déclaré',
                $isEmptied => self::COUNTED_EMPTY,
                default => self::COUNTED,
            };
            // Le journal garde toujours le flacon compté, à l'heure du compte, même sans écart
            // (huitième et neuvième vérifications) ; la ligne de stock garde ce dernier compte
            $line = $this->journal($produit, $cliniqueId, $author, 0, $gap, $label, MotifAjustementStock::Inventaire, null, ['opening_number' => $counted]);
            $stock->update([
                'opened_rest' => round($rest, 3),
                'opening_number' => $isEmptied ? $this->nextOpening($stock) : $counted,
                'opened_at' => $rest <= 0 ? null : ($isDeclared ? now() : ($stock->opened_at ?? now())),
                'last_count_id' => $line->id,
                'last_counted_at' => now(),
            ]);

            return $gap;
        });
    }

    /**
     * La contenance change (quatrième vérification) : dans la même unité, un
     * reste plus grand qu'une unité est ramené à la contenance ; dans une
     * autre unité (ou sans contenance), il ne se convertit pas et est remis
     * à zéro. Chaque changement laisse une trace au journal.
     */
    public function changeContent(Produit $produit, ?float $content, ?string $doseUnit, ?User $author = null): Produit
    {
        // L'article d'abord, puis ses stocks : le même ordre partout (dixième vérification)
        return DB::transaction(function () use ($produit, $content, $doseUnit, $author): Produit {
            $locked = $this->lockedProduct($produit);
            $oldUnit = $locked->dose_unit;
            $produit->forceFill(['content_per_unit' => $content, 'dose_unit' => $content === null ? null : $doseUnit])->save();
            $this->adaptOpenedToContent($produit, $oldUnit, $author);

            return $produit;
        });
    }

    /**
     * Après un changement de contenance, déjà sous le verrou de l'article
     * (changeContent) : le flacon entamé de chaque clinique suit.
     */
    private function adaptOpenedToContent(Produit $produit, ?string $oldUnit, ?User $author = null): void
    {
        $content = (float) $produit->content_per_unit;
        $isSameUnit = $content > 0 && $oldUnit !== null && $oldUnit === $produit->dose_unit;
        DB::transaction(function () use ($produit, $content, $isSameUnit, $oldUnit, $author): void {
            $stocks = $this->lockedStocks($produit)->filter(fn (Stock $stock): bool => (float) $stock->opened_rest > 0);
            foreach ($stocks as $stock) {
                $rest = (float) $stock->opened_rest;
                $new = $isSameUnit ? min($rest, $content) : 0.0;
                if ($new === $rest) {
                    continue;
                }
                // Remis à zéro : ce flacon n'existe plus, son numéro ne revient pas (septième vérification)
                $reset = $new <= 0 ? $stock->opening_number : null;
                $stock->update(['opened_rest' => $new, 'opened_at' => $new > 0 ? $stock->opened_at : null, 'opening_number' => $reset !== null ? $this->nextOpening($stock) : $stock->opening_number]);
                $label = $isSameUnit
                    ? 'Contenance changée : reste entamé ramené à '.self::formatDose($new).' '.$produit->dose_unit
                    : 'Contenance changée : reste entamé de '.self::formatDose($rest).' '.$oldUnit.' remis à zéro';
                $this->journal($produit, $stock->clinique_id, $author, 0, round($new - $rest, 3), $label, MotifAjustementStock::Inventaire, null, $reset !== null ? ['opening_number' => $reset] : []);
            }
        });
    }

    /**
     * Le numéro d'une nouvelle ouverture : toujours plus grand que tous ceux
     * déjà servis pour ce stock, même après un retour au flacon d'avant
     * (huitième vérification). Un numéro ne resert jamais.
     */
    private function nextOpening(Stock $stock): int
    {
        // Lu et gardé sur la ligne de stock verrouillée, jamais dans le journal (neuvième vérification)
        $next = max((int) $stock->last_opening_number, (int) $stock->opening_number) + 1;
        $stock->last_opening_number = $next;

        return $next;
    }

    private static function formatDose(float $dose): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($dose, 3, '.', ''), '0'), '.'));
    }

    /**
     * Pour une annulation : l'article puis son stock, verrouillés avant de lire
     * le journal (neuvième et dixième vérifications).
     */
    public function lockProductAndStock(Produit $produit, int $cliniqueId): Produit
    {
        $locked = $this->lockedProduct($produit);
        $this->lockedStock($locked, $cliniqueId, isCreating: false);

        // L'article relu sous son verrou : giveBackTake travaille avec lui
        return $locked;
    }

    /** L'article relu sous son verrou : toujours avant son stock (dixième vérification) */
    protected function lockedProduct(Produit $produit): Produit
    {
        return Produit::query()->withoutGlobalScopes()->whereKey($produit->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Toutes les lignes de stock d'un article, verrouillées par identifiant croissant
     *
     * @return Collection<int, Stock>
     */
    protected function lockedStocks(Produit $produit): Collection
    {
        return Stock::query()->withoutGlobalScope('clinique')->where('produit_id', $produit->id)->orderBy('id')->lockForUpdate()->get();
    }

    /** Le stock d'un produit dans une clinique, verrouillé (créé à zéro s'il manque, si demandé) */
    protected function lockedStock(Produit $produit, int $cliniqueId, bool $isCreating = true): ?Stock
    {
        $stock = Stock::query()->withoutGlobalScope('clinique')
            ->where('produit_id', $produit->id)->where('clinique_id', $cliniqueId)->lockForUpdate()->first();

        return $stock ?? ($isCreating ? Stock::withoutEvents(fn (): Stock => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId, 'quantite' => 0])) : null);
    }

    /** @param  array<string, mixed>  $extra */
    private function journal(Produit $produit, int $cliniqueId, ?User $author, int $units, ?float $dose, string $label, ?MotifAjustementStock $motif, ?Model $source, array $extra = []): MouvementStock
    {
        return MouvementStock::withoutEvents(fn (): MouvementStock => MouvementStock::create([
            ...$extra,
            // L'unité de la dose, figée au mouvement (sixième vérification)
            'dose_unit' => $extra['dose_unit'] ?? ($dose !== null ? $produit->dose_unit : null),
            'produit_id' => $produit->id,
            'clinique_id' => $cliniqueId,
            'user_id' => $author?->id,
            'quantite' => $units,
            'dose' => $dose,
            'libelle' => $label,
            'motif' => $motif,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'created_at' => now(),
        ]));
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
     * Le stock d'un stupéfiant aligné sur le solde de son registre (arrondi
     * à l'unité entière inférieure : le registre compte au dixième de ml).
     */
    public function alignerSurLeRegistre(Produit $produit, MouvementStupefiant $mouvement, ?User $auteur = null): void
    {
        $solde = (float) MouvementStupefiant::withoutGlobalScopes()
            ->where('clinique_id', $mouvement->clinique_id)
            ->where('produit_id', $produit->id)
            ->lockForUpdate()
            ->sum('quantite');
        $actuel = (int) Stock::query()->withoutGlobalScope('clinique')
            ->where('produit_id', $produit->id)
            ->where('clinique_id', $mouvement->clinique_id)
            ->value('quantite');
        $ecart = (int) floor($solde + 0.000001) - $actuel;

        if ($ecart !== 0) {
            $this->appliquer($produit, $ecart, 'Registre des stupéfiants : '.$mouvement->motif, $auteur, source: $mouvement, cliniqueId: $mouvement->clinique_id);
        }
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
