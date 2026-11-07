<?php

namespace App\Services\Stocks;

use App\Enums\MotifAjustementStock;
use App\Enums\TypeMouvementStupefiant;
use App\Models\Clinique;
use App\Models\Lot;
use App\Models\LotAllocation;
use App\Models\MouvementStock;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Sejour;
use App\Models\SejourSoin;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Le décompte des lots à chaque mouvement de stock (FEFO, décision de Jim
 * du 04.11.2026). Un seul point d'entrée : GestionnaireStock l'appelle pour
 * chaque mouvement qui change le nombre d'unités, dans sa transaction,
 * l'article et son stock déjà verrouillés (deux sorties sur le même lot
 * passent l'une après l'autre ; les lots sont relus sous verrou).
 *
 * Une sortie prend, dans la clinique du mouvement :
 *  1. le lot imposé, s'il y en a un (lot écarté, lot inscrit au registre
 *     d'un stupéfiant) ;
 *  2. les lots datés et pas encore périmés, celui qui périme le premier
 *     d'abord, puis le suivant ;
 *  3. les lots sans péremption (matériel, accessoires) ;
 *  4. le stock sans lot connu (plus de stock que de lots) : la sortie y est
 *     gardée « sans lot » ;
 *  5. les lots périmés, en dernier : on ne suppose jamais qu'un lot périmé
 *     a été donné tant qu'il reste autre chose ; il reste « Périmé » jusqu'à
 *     être écarté. « Périmé : écarté » (motif péremption) les prend d'abord.
 *
 * Une entrée qui annule une sortie (vente annulée, soin retiré, +1, ligne
 * corrigée du registre) rend à chaque lot ce que la sortie y avait pris, la
 * dernière prise d'abord ; un lot écarté entre-temps renaît, avec son numéro
 * et sa péremption. Une entrée sans sortie à rendre (réception manuelle) est
 * du stock sans lot connu ; la réception d'une commande crée son lot.
 * Un +1 ne fait jamais revivre un lot écarté, ni ne rend à un lot sorti
 * comme périmé.
 *
 * Une instance par requête ou par tâche (`Scoped`) : le premier jour du
 * mois de chaque clinique se lit une fois. Les lots s'enregistrent sans
 * événement : la ligne de stock, qui change avec eux, annonce les alertes
 * une seule fois par mouvement.
 */
#[Scoped]
class FefoLots
{
    /** @var array<int, Carbon> le premier jour du mois, par clinique */
    private array $monthStarts = [];

    /**
     * Suit un mouvement du journal dans les lots.
     *
     * @param  int  $stockBefore  les unités fermées avant le mouvement
     * @param  Lot|null  $lot  le lot imposé (sortie) ou qui reçoit (entrée)
     * @param  iterable<MouvementStock>  $reverses  les mouvements que celui-ci annule
     */
    public function follow(MouvementStock $movement, int $stockBefore, ?Model $source = null, ?MotifAjustementStock $motif = null, ?Lot $lot = null, iterable $reverses = []): void
    {
        $units = (int) $movement->quantite;
        if ($units < 0) {
            $this->take($movement, -$units, $stockBefore, $source, $motif, $lot ?? $this->registerLot($source));
        } elseif ($units > 0) {
            $this->giveBack($movement, $units, $source, $motif, $lot ?? $this->registerLot($source), collect($reverses));
        }
    }

    private function take(MouvementStock $movement, int $count, int $stockBefore, ?Model $source, ?MotifAjustementStock $motif, ?Lot $first): void
    {
        $lots = $this->lockedLots($movement->produit_id, $movement->clinique_id);
        // Un lot imposé vide n'est pas ignoré en silence (une ligne du registre, elle, est contrôlée en ml
        // avant d'écrire : un reste en dixièmes peut y être sans unité entière au stock)
        if ($first !== null && ! $lots->contains('id', $first->id) && ! $source instanceof MouvementStupefiant) {
            throw self::emptyLot($first);
        }
        $recipient = $this->recipientOf($source);
        // Un périmé écarté, ou éliminé au registre : les lots périmés d'abord
        $isDiscard = $motif === MotifAjustementStock::Peremption || ($source instanceof MouvementStupefiant && $source->type === TypeMouvementStupefiant::Elimination);
        $buckets = $this->buckets($lots, $stockBefore, $movement->clinique_id, $isDiscard, $first?->id);

        foreach ($buckets as ['lot' => $lot, 'available' => $available]) {
            if ($count <= 0) {
                break;
            }
            $taken = min($count, $available);
            if ($taken <= 0) {
                continue;
            }
            if ($lot !== null) {
                $lot->forceFill(['quantite' => (int) $lot->quantite - $taken])->saveQuietly();
            }
            $this->record(LotAllocation::TAKE, $movement, $lot, $lot?->numero_lot, $lot?->peremption_le, -$taken, null, $recipient);
            $count -= $taken;
        }

        // Jamais au-delà des unités du stock ; par prudence, un reste éventuel est gardé sans lot
        if ($count > 0) {
            $this->record(LotAllocation::TAKE, $movement, null, null, null, -$count, null, $recipient);
        }
    }

    /** @param  \Illuminate\Support\Collection<int, MouvementStock>  $reverses */
    private function giveBack(MouvementStock $movement, int $count, ?Model $source, ?MotifAjustementStock $motif, ?Lot $into, \Illuminate\Support\Collection $reverses): void
    {
        foreach ($this->takesToGiveBack($movement, $source, $motif, $reverses, $count) as $take) {
            if ($count <= 0) {
                break;
            }
            $back = min($count, -$take->quantity - (int) $take->returned);
            if ($back <= 0) {
                continue;
            }
            $lot = $this->lotOf($take);
            if ($lot !== null) {
                $lot->forceFill(['quantite' => (int) $lot->quantite + $back])->saveQuietly();
            }
            $this->record(LotAllocation::RETURN, $movement, $lot, $take->lot_number, $take->expires_on, $back, $take->id, ['patient_id' => $take->patient_id, 'client_id' => $take->client_id]);
            $count -= $back;
        }

        // Ce qui reste entre dans le lot désigné (celui de la ligne du registre) ; sans lui, sans lot connu
        if ($count > 0 && $into !== null) {
            $into->forceFill(['quantite' => (int) $into->quantite + $count])->saveQuietly();
            $this->record(LotAllocation::ENTRY, $movement, $into, $into->numero_lot, $into->peremption_le, $count, null, $this->recipientOf($source));
        }
    }

    /**
     * Les prises qu'une entrée rend, la dernière d'abord, avec ce qui leur a
     * déjà été rendu (`returned`) :
     *  - les mouvements annulés (facture, ordonnance, soin) ;
     *  - la ligne corrigée du registre, pour un stupéfiant ;
     *  - pour un ajustement manuel en plus (+1, sauf réception) : les
     *    derniers ajustements manuels en moins pas encore rendus, sauf ceux
     *    sortis comme périmés et ceux dont le lot a été écarté depuis.
     *
     * Tout se relit sous verrou : sous MySQL (REPEATABLE READ), une lecture
     * ordinaire verrait l'instantané du début de la transaction, sans les
     * rendus écrits entre-temps par une autre annulation.
     *
     * @param  \Illuminate\Support\Collection<int, MouvementStock>  $reverses
     * @return Collection<int, LotAllocation>
     */
    private function takesToGiveBack(MouvementStock $movement, ?Model $source, ?MotifAjustementStock $motif, \Illuminate\Support\Collection $reverses, int $count): Collection
    {
        $takes = fn () => LotAllocation::query()->withoutGlobalScopes()
            ->where('kind', LotAllocation::TAKE)
            ->where('produit_id', $movement->produit_id)
            ->where('clinique_id', $movement->clinique_id)
            ->orderByDesc('id');

        if ($reverses->isNotEmpty()) {
            return $this->withReturned($takes()->whereIn('mouvement_stock_id', $reverses->pluck('id'))->lockForUpdate()->get());
        }
        if ($source instanceof MouvementStupefiant && $source->type === TypeMouvementStupefiant::Correction && $source->corrige_id !== null) {
            $corrected = MouvementStock::query()->withoutGlobalScopes()->where('source_type', $source->getMorphClass())->where('source_id', $source->corrige_id)->lockForUpdate()->pluck('id');

            return $this->withReturned($takes()->whereIn('mouvement_stock_id', $corrected)->lockForUpdate()->get());
        }
        if ($source !== null || $motif === null || $motif === MotifAjustementStock::Reception) {
            return new Collection;
        }

        // Un +1 : les derniers −N manuels, page par page, jusqu'à en avoir assez à rendre
        $manual = MouvementStock::query()->withoutGlobalScopes()->select('id')
            ->whereColumn('mouvements_stock.id', 'lot_allocations.mouvement_stock_id')
            ->whereNull('source_type')->whereNotNull('motif')->where('motif', '!=', MotifAjustementStock::Peremption->value);
        $found = new Collection;
        $left = $count;
        $before = null;
        do {
            $page = $this->withReturned($takes()->whereExists($manual->toBase())
                // Le lot écarté depuis ne revit pas : seules les prises dont le lot est là, ou sans lot
                ->where(fn ($q) => $q->whereNotNull('lot_id')->orWhereNull('lot_number'))
                ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
                ->limit(50)->lockForUpdate()->get());
            foreach ($page as $take) {
                $remaining = -$take->quantity - (int) $take->returned;
                if ($remaining > 0) {
                    $found->push($take);
                    $left -= $remaining;
                }
            }
            $before = $page->last()?->id;
        } while ($left > 0 && $page->count() === 50);

        return $found;
    }

    /**
     * Ce qui a déjà été rendu à chaque prise, relu sous verrou et additionné ici
     *
     * @param  Collection<int, LotAllocation>  $takes
     * @return Collection<int, LotAllocation>
     */
    private function withReturned(Collection $takes): Collection
    {
        $returned = $takes->isEmpty() ? collect() : LotAllocation::query()->withoutGlobalScopes()
            ->whereIn('returns_allocation_id', $takes->pluck('id'))
            ->lockForUpdate()->get(['returns_allocation_id', 'quantity'])
            ->groupBy('returns_allocation_id')
            ->map(fn ($returns): int => (int) $returns->sum('quantity'));

        return $takes->each(fn (LotAllocation $take) => $take->setAttribute('returned', $returned->get($take->id, 0)));
    }

    /** Le lot d'une prise, sous verrou ; écarté depuis, il renaît vide avec son numéro et sa péremption */
    private function lotOf(LotAllocation $take): ?Lot
    {
        if ($take->lot_id !== null && ($lot = Lot::withoutGlobalScopes()->whereKey($take->lot_id)->lockForUpdate()->first()) !== null) {
            return $lot;
        }
        if ($take->lot_number === null) {
            return null;
        }

        return $this->lotByNumber($take->produit_id, $take->clinique_id, $take->lot_number, $take->expires_on);
    }

    /** Le lot inscrit sur une ligne du registre des stupéfiants, sous verrou (renaît s'il a été écarté) */
    private function registerLot(?Model $source): ?Lot
    {
        if (! $source instanceof MouvementStupefiant || $source->numero_lot === null) {
            return null;
        }
        if ($source->lot_id !== null && ($lot = Lot::withoutGlobalScopes()->whereKey($source->lot_id)->lockForUpdate()->first()) !== null) {
            return $lot;
        }

        return $this->lotByNumber($source->produit_id, $source->clinique_id, $source->numero_lot, null);
    }

    /**
     * Le lot d'un numéro, sous verrou, cherché une seule fois par son numéro ;
     * écarté depuis, il renaît vide avec sa péremption (celle donnée, sinon la
     * dernière que son décompte a figée).
     */
    public function lotByNumber(int $produitId, int $cliniqueId, string $number, ?Carbon $expiresOn = null): Lot
    {
        $lot = Lot::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->where('produit_id', $produitId)->where('numero_lot', $number)
            ->orderBy('id')->lockForUpdate()->first();
        if ($lot !== null) {
            return $lot;
        }
        $peremption = $expiresOn?->toDateString() ?? LotAllocation::withoutGlobalScopes()->where('produit_id', $produitId)->where('clinique_id', $cliniqueId)
            ->where('lot_number', $number)->whereNotNull('expires_on')->orderByDesc('id')->first()?->expires_on?->toDateString();

        // Hors des événements du trait : la clinique du lot peut être une clinique sœur
        return Lot::withoutEvents(fn (): Lot => Lot::create([
            'produit_id' => $produitId, 'clinique_id' => $cliniqueId, 'numero_lot' => $number, 'peremption_le' => $peremption, 'quantite' => 0,
        ]));
    }

    /** @return Collection<int, Lot> */
    private function lockedLots(int $produitId, int $cliniqueId): Collection
    {
        return Lot::withoutGlobalScopes()->where('produit_id', $produitId)->where('clinique_id', $cliniqueId)
            ->where('quantite', '>', 0)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * L'ordre FEFO, chaque étape avec ce qu'elle peut donner ; `lot` null :
     * le stock sans lot connu.
     *
     * @param  Collection<int, Lot>  $lots
     * @return list<array{lot: Lot|null, available: int}>
     */
    private function buckets(Collection $lots, int $stockBefore, int $cliniqueId, bool $isExpiredFirst, ?int $firstId): array
    {
        $unlotted = max(0, $stockBefore - (int) $lots->sum('quantite'));

        return array_map(fn (?Lot $lot): array => ['lot' => $lot, 'available' => $lot === null ? $unlotted : (int) $lot->quantite], $this->order($lots, $cliniqueId, $isExpiredFirst, $firstId));
    }

    /**
     * L'ordre FEFO des lots d'une clinique ; `null` marque la place du stock
     * sans lot connu. Servi aussi au registre des stupéfiants, qui compte en ml.
     *
     * @param  Collection<int, Lot>  $lots
     * @return list<Lot|null>
     */
    public function order(Collection $lots, int $cliniqueId, bool $isExpiredFirst, ?int $firstId): array
    {
        $monthStart = $this->monthStart($cliniqueId);
        $isExpired = fn (Lot $lot): bool => $lot->peremption_le !== null && $lot->peremption_le->lt($monthStart);
        $byDate = fn ($group) => $group->sortBy([fn (Lot $a, Lot $b): int => $a->peremption_le <=> $b->peremption_le, fn (Lot $a, Lot $b): int => $a->id <=> $b->id])->values()->all();

        $first = $firstId !== null ? $lots->firstWhere('id', $firstId) : null;
        $others = $lots->reject(fn (Lot $lot): bool => $lot->id === $first?->id);
        $expired = $byDate($others->filter($isExpired));
        $valid = $byDate($others->reject($isExpired)->whereNotNull('peremption_le'));
        $undated = $others->whereNull('peremption_le')->sortBy('id')->values()->all();

        $order = $isExpiredFirst ? [...$expired, ...$valid, ...$undated, null] : [...$valid, ...$undated, null, ...$expired];
        if ($first !== null) {
            array_unshift($order, $first);
        }

        return $order;
    }

    /** Le refus d'une sortie imposée sur un lot vide */
    public static function emptyLot(Lot $lot, string $field = 'quantite'): ValidationException
    {
        return ValidationException::withMessages([$field => 'Le lot '.$lot->numero_lot.' est vide : il n’en reste rien à sortir.']);
    }

    /** Le premier jour du mois à la clinique : avant lui, un lot est périmé (comme la surveillance) */
    private function monthStart(int $cliniqueId): Carbon
    {
        return $this->monthStarts[$cliniqueId] ??= aujourdhui(Clinique::withoutGlobalScopes()->find($cliniqueId))->startOfMonth();
    }

    /**
     * L'animal et le client d'une sortie, lus sur sa source : facture,
     * ordonnance, commande en ligne, soin d'un séjour, ligne du registre.
     *
     * @return array{patient_id: int|null, client_id: int|null}
     */
    private function recipientOf(?Model $source): array
    {
        if ($source instanceof SejourSoin) {
            $source = Sejour::withoutGlobalScopes()->find($source->sejour_id);
        }
        $patientId = $source?->getAttribute('patient_id');
        $clientId = $source?->getAttribute('client_id');
        if ($clientId === null && $patientId !== null) {
            $clientId = Patient::withoutGlobalScopes()->whereKey($patientId)->value('client_id');
        }

        return ['patient_id' => $patientId !== null ? (int) $patientId : null, 'client_id' => $clientId !== null ? (int) $clientId : null];
    }

    /** @param  array{patient_id: int|null, client_id: int|null}  $recipient */
    private function record(string $kind, MouvementStock $movement, ?Lot $lot, ?string $number, ?Carbon $expiresOn, int $quantity, ?int $returns, array $recipient): void
    {
        // Hors des événements du trait clinique, comme le journal : le mouvement peut viser une clinique sœur
        LotAllocation::withoutEvents(fn (): LotAllocation => LotAllocation::create([
            'kind' => $kind,
            'mouvement_stock_id' => $movement->id,
            'produit_id' => $movement->produit_id,
            'clinique_id' => $movement->clinique_id,
            'lot_id' => $lot?->id,
            'lot_number' => $number,
            'expires_on' => $expiresOn,
            'quantity' => $quantity,
            'returns_allocation_id' => $returns,
            ...$recipient,
            'created_at' => now(),
        ]));
    }
}
