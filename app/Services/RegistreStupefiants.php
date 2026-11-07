<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\TypeMouvementStupefiant;
use App\Models\Clinique;
use App\Models\Lot;
use App\Models\LotAllocation;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\User;
use App\Services\Stocks\FefoLots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * L'écriture au registre des stupéfiants (24.10.2026, mise aux normes le
 * 01.11.2026) : une seule porte pour chaque ligne, qu'elle vienne du
 * registre lui-même, de la réception d'une commande ou du retrait d'un
 * lot périmé. La substance est verrouillée le temps de l'écriture : le
 * stock lu reste vrai, une sortie ne le dépasse jamais. Chaque ligne garde
 * le stock après elle et les noms figés (animal, détenteur, signataire).
 * Le stock de l'article suit le registre. À appeler dans une transaction.
 *
 * Chaque ligne qui fait bouger la substance inscrit son lot (04.11.2026) :
 * celui choisi, celui de la ligne corrigée, sinon celui qu'une sortie
 * prend d'abord (FEFO). Le stock sort de ce lot, ou y revient : un
 * stupéfiant suit son registre, son lot est celui inscrit. Une sortie plus
 * grande que son lot s'écrit en une ligne par lot (06.11.2026), chacune avec
 * son stock après elle ; les suivantes pointent la première.
 */
class RegistreStupefiants
{
    public function __construct(private GestionnaireStock $stock) {}

    /**
     * Écrit une inscription et rend sa dernière ligne (le stock après elle).
     *
     * @param  float  $quantite  signée (+ entre, − sort ; 0 pour un visa)
     * @param  array<string, mixed>  $details  motif, patient, ordonnance, ordonnance_officielle, ordonnance_officielle_le, fournisseur, bulletin, bulletin_le, corrige_id, quantite_comptee, lot
     */
    public function ecrire(Produit $produit, TypeMouvementStupefiant $type, float $quantite, array $details, int $cliniqueId, ?User $auteur): MouvementStupefiant
    {
        return $this->ecrireParLot($produit, $type, $quantite, $details, $cliniqueId, $auteur)->last();
    }

    /**
     * Écrit une inscription, une ligne par lot, dans l'ordre (06.11.2026).
     *
     * @param  array<string, mixed>  $details
     * @return Collection<int, MouvementStupefiant>
     */
    public function ecrireParLot(Produit $produit, TypeMouvementStupefiant $type, float $quantite, array $details, int $cliniqueId, ?User $auteur): Collection
    {
        $solde = $this->soldeVerrouille($produit, $cliniqueId);
        if ($quantite < 0 && $solde + $quantite < -0.0005) {
            throw ValidationException::withMessages([
                'quantite' => 'Le registre n’en compte que '.self::quantite(max(0, $solde)).' : une sortie ne dépasse jamais le stock.',
            ]);
        }

        // Un formulaire officiel du tableau a ne sert qu'à une remise par médicament : il ne se renouvelle pas (art. 51,
        // al. 3 OCStup ; décision de Jim, 01.11.2026). Lu sous le verrou de la substance, dans toute l'organisation
        if ($type === TypeMouvementStupefiant::Remise && filled($details['ordonnance_officielle'] ?? null)) {
            $organisation = Clinique::query()->find($cliniqueId)?->societe?->organisation_id;
            $cliniques = Clinique::withoutGlobalScopes()->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $organisation))->pluck('id');
            // Lecture verrouillante (une lecture ordinaire verrait sous MySQL l'instantané du début de la transaction) ;
            // une remise entièrement annulée par ses corrections ne compte pas : elle se refait sur le bon animal
            $remises = MouvementStupefiant::withoutGlobalScopes()->whereIn('clinique_id', $cliniques)->where('produit_id', $produit->id)
                ->where('type', TypeMouvementStupefiant::Remise)->whereNull('ordonnance_id')->where('ordonnance_numero', $details['ordonnance_officielle'])
                ->lockForUpdate()->get(['id', 'quantite']);
            $corrections = MouvementStupefiant::withoutGlobalScopes()->whereIn('corrige_id', $remises->pluck('id'))->where('type', TypeMouvementStupefiant::Correction)
                ->lockForUpdate()->get(['corrige_id', 'quantite'])->groupBy('corrige_id');
            $dejaServi = $remises->contains(fn (MouvementStupefiant $r): bool => abs((float) $r->quantite + (float) ($corrections[$r->id] ?? collect())->sum('quantite')) > 0.0005);
            if ($dejaServi) {
                throw ValidationException::withMessages(['ordonnance_officielle' => 'Ce formulaire officiel a déjà servi à la remise de ce médicament : une ordonnance du tableau a ne se renouvelle pas (art. 51, al. 3 OCStup).']);
            }
        }

        /** @var Patient|null $patient */
        $patient = $details['patient'] ?? null;
        /** @var Ordonnance|null $ordonnance */
        $ordonnance = $details['ordonnance'] ?? null;
        $employe = app(ContexteClinique::class)->employe();
        $dateRegistre = $this->dateDeLaLigne($produit, $type, $details, $cliniqueId);
        $lignes = collect();

        $parts = $this->partsParLot($produit, $type, $quantite, $details, $cliniqueId, $solde);
        foreach ($parts as $rang => [$lotId, $numeroLot, $part]) {
            // La clinique visée peut être une clinique sœur (ajustement de son
            // stock) : posée ici, hors des événements qui imposent celle du jeton
            $mouvement = MouvementStupefiant::withoutEvents(fn (): MouvementStupefiant => MouvementStupefiant::create([
                'clinique_id' => $cliniqueId,
                'produit_id' => $produit->id,
                // Le lot de la ligne, son numéro figé : le stock sort de lui, ou y revient
                'lot_id' => $lotId,
                'numero_lot' => $numeroLot,
                'type' => $type,
                'patient_id' => $patient?->id,
                'employe_id' => $employe?->id,
                'quantite' => round($part, 3),
                'solde_apres' => round($solde + $part, 3),
                'corrige_id' => $details['corrige_id'] ?? null,
                // Les lignes suivantes d'une même inscription pointent la première
                'premiere_ligne_id' => $lignes->first()?->id,
                // Le compte d'un inventaire : sur la dernière ligne, celle où le stock est celui compté
                'quantite_comptee' => $rang === array_key_last($parts) ? ($details['quantite_comptee'] ?? null) : null,
                'motif' => (string) ($details['motif'] ?? $type->libelle()),
                // Figés à l'inscription : renommer quelqu'un ne change pas le registre
                'patient_nom' => $patient?->nom,
                'detenteur_nom' => $patient?->client?->nom_affiche,
                'signataire_nom' => $employe->nom ?? $auteur?->name,
                // L'ordonnance de l'app et son numéro figé, ou le numéro du formulaire officiel du tableau a (01.11.2026)
                'ordonnance_id' => $ordonnance?->id,
                'ordonnance_numero' => $ordonnance->numero ?? ($details['ordonnance_officielle'] ?? null),
                'ordonnance_le' => $details['ordonnance_officielle_le'] ?? null,
                'fournisseur' => $details['fournisseur'] ?? null,
                'bulletin' => $details['bulletin'] ?? null,
                'bulletin_le' => $details['bulletin_le'] ?? null,
                'date_registre' => $dateRegistre,
                'created_at' => now(),
            ]));

            if ($part !== 0.0) {
                $this->stock->alignerSurLeRegistre($produit, $mouvement, $auteur);
            }
            $solde = round($solde + $part, 3);
            $lignes->push($mouvement);
        }

        return $lignes;
    }

    /**
     * Les lignes d'une inscription, une par lot (06.11.2026), sous le verrou
     * de la substance, chacune `[lot_id, numero_lot, quantité signée]` :
     *  - une correction : le lot de la ligne corrigée ; en moins, jamais
     *    au-delà de ce qu'il contient (un lot vide est refusé). Une ligne
     *    sans lot corrigée en moins (07.11.2026) : ce que le stock sans lot
     *    couvre encore reste sans lot, le reste sort des lots en FEFO, une
     *    ligne par lot, avec le même `corrige_id` ;
     *  - une sortie (administration, remise, perte, vol, retour,
     *    élimination) et un écart d'inventaire en moins (décision de Jim,
     *    07.11.2026) : le lot choisi d'abord, puis l'ordre FEFO (les périmés
     *    en dernier, en premier pour une élimination), une ligne par lot ; le
     *    stock sans lot connu donne une ligne sans lot ;
     *  - une entrée : le lot donné (réception d'une commande), sinon aucun ;
     *    un écart d'inventaire en plus reste sans lot (ce qui est retrouvé au
     *    compte ne dit pas de quel lot il vient : il compte comme stock sans
     *    lot connu, pris après les lots valides) ;
     *  - un visa : une ligne, sans lot.
     *
     * @param  array<string, mixed>  $details
     * @return list<array{0: int|null, 1: string|null, 2: float}>
     */
    private function partsParLot(Produit $produit, TypeMouvementStupefiant $type, float $quantite, array $details, int $cliniqueId, float $solde): array
    {
        $lot = ($details['lot'] ?? null) instanceof Lot ? $details['lot'] : null;

        if ($type === TypeMouvementStupefiant::Correction) {
            $corrigee = MouvementStupefiant::withoutGlobalScopes()->whereKey($details['corrige_id'] ?? null)->lockForUpdate()->first();
            $lotId = $corrigee?->lot_id;
            // Son lot écarté depuis : retrouvé (ou recréé) une seule fois, avec sa péremption, et posé sur la ligne
            if ($lotId === null && $corrigee?->numero_lot !== null && $quantite > 0) {
                $lotId = app(FefoLots::class)->lotByNumber($produit->id, $cliniqueId, $corrigee->numero_lot)->id;
            }
            if ($quantite < 0 && $corrigee?->numero_lot !== null) {
                $contenu = $lotId !== null ? ($this->contenuDesLots($produit->id, $cliniqueId)[1][$lotId] ?? 0.0) : 0.0;
                if ($contenu + $quantite < -0.0005) {
                    throw ValidationException::withMessages(['quantite' => $contenu <= 0.0005
                        ? 'Le lot '.$corrigee->numero_lot.' est vide : il n’en reste rien à sortir.'
                        : 'Le lot '.$corrigee->numero_lot.' n’en compte plus que '.self::quantite($contenu).' : la correction ne le dépasse pas.']);
                }
            }

            // Une ligne sans lot corrigée en moins (décision de Jim, 07.11.2026) : ce que le stock sans lot couvre
            // encore reste sans lot ; le reste sort des lots, comme un écart d'inventaire, une ligne par lot
            if ($quantite < 0 && $corrigee !== null && $corrigee->numero_lot === null) {
                return $this->repartir($produit, $cliniqueId, $quantite, $solde, null, false, true);
            }

            return [[$lotId, $corrigee?->numero_lot, $quantite]];
        }
        if ($quantite >= 0 || ($type->sens() !== -1 && $type !== TypeMouvementStupefiant::Inventaire)) {
            return [[$lot?->id, $lot?->numero_lot, $quantite]];
        }

        // Un lot vide se dit sur le champ du lot, pour que le formulaire le montre (07.11.2026)
        return $this->repartir($produit, $cliniqueId, $quantite, $solde, $lot, $type === TypeMouvementStupefiant::Elimination, false);
    }

    /**
     * Une sortie répartie sur les lots, une ligne par lot : le lot imposé
     * d'abord (refusé s'il est vide), puis l'ordre FEFO, le stock sans lot
     * connu à sa place (ou d'abord, pour corriger une ligne sans lot).
     *
     * @return list<array{0: int|null, 1: string|null, 2: float}>
     */
    private function repartir(Produit $produit, int $cliniqueId, float $quantite, float $solde, ?Lot $lot, bool $isExpiredFirst, bool $isSansLotDabord, string $champ = 'lot_id'): array
    {
        [$lots, $contenus] = $this->contenuDesLots($produit->id, $cliniqueId);
        if ($lot !== null && ($contenus[$lot->id] ?? 0.0) <= 0.0005) {
            throw FefoLots::emptyLot($lot, $champ);
        }
        $sansLot = max(0.0, round($solde - array_sum($contenus), 3));
        $pleins = $lots->filter(fn (Lot $l): bool => ($contenus[$l->id] ?? 0.0) > 0.0005)->values();
        $ordre = app(FefoLots::class)->order($pleins, $cliniqueId, $isExpiredFirst, $lot?->id);
        if ($isSansLotDabord) {
            $ordre = [null, ...array_values(array_filter($ordre, fn (?Lot $l): bool => $l !== null))];
        }

        $reste = round(-$quantite, 3);
        $parts = [];
        foreach ($ordre as $l) {
            $pris = round(min($reste, $l === null ? $sansLot : $contenus[$l->id]), 3);
            if ($pris <= 0) {
                continue;
            }
            $parts[] = [$l?->id, $l?->numero_lot, -$pris];
            $reste = round($reste - $pris, 3);
            if ($reste <= 0) {
                break;
            }
        }
        // Le registre ne sort jamais plus que son solde ; par prudence, un reste éventuel est sans lot
        if ($reste > 0) {
            $parts[] = [null, null, -$reste];
        }

        return $parts;
    }

    /**
     * Ce que chaque lot d'une substance contient vraiment, en ml (06.11.2026) :
     * le stock compte des unités entières, le registre des dixièmes. Le lot
     * garde ce que le registre y a inscrit : sa quantité, plus ce que le
     * stock en a sorti pour le registre, moins ce que le registre y a écrit
     * (les lignes de ce lot). Tout relu sous verrou.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, Lot>, 1: array<int, float>}
     */
    public function contenuDesLots(int $produitId, int $cliniqueId): array
    {
        // Relu par substance (clinique et article), l'intervalle déjà tenu par soldeVerrouille, et regroupé ici :
        // un verrou par liste de lots poserait des verrous d'intervalle entre substances voisines (interblocage)
        $lots = Lot::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->where('produit_id', $produitId)->orderBy('id')->lockForUpdate()->get();
        $inscrit = MouvementStupefiant::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->where('produit_id', $produitId)->whereNotNull('lot_id')
            ->lockForUpdate()->get(['lot_id', 'quantite'])
            ->groupBy('lot_id')->map(fn ($lignes): float => (float) $lignes->sum(fn ($l): float => (float) $l->quantite));
        // Les sorties et entrées du stock faites pour le registre, jointes à leur mouvement (verrouillé lui aussi)
        $suivi = LotAllocation::withoutGlobalScopes()->join('mouvements_stock', 'mouvements_stock.id', '=', 'lot_allocations.mouvement_stock_id')
            ->where('lot_allocations.produit_id', $produitId)->where('lot_allocations.clinique_id', $cliniqueId)->whereNotNull('lot_allocations.lot_id')
            ->where('mouvements_stock.source_type', (new MouvementStupefiant)->getMorphClass())
            ->lockForUpdate()->get(['lot_allocations.lot_id', 'lot_allocations.quantity'])
            ->groupBy('lot_id')->map(fn ($lignes): int => (int) $lignes->sum('quantity'));

        $contenus = [];
        foreach ($lots as $lot) {
            $contenus[$lot->id] = max(0.0, round((int) $lot->quantite - ($suivi[$lot->id] ?? 0) + ($inscrit[$lot->id] ?? 0.0), 3));
        }

        return [$lots, $contenus];
    }

    /**
     * Le même contenu en ml, pour une liste de lots à lire (sans verrou, sans
     * écrire) : la liste des lots le montre pour un stupéfiant.
     *
     * @param  iterable<Lot>  $lots
     * @return array<int, float>
     */
    public static function contenusALire(iterable $lots): array
    {
        $ids = collect($lots)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $inscrit = MouvementStupefiant::withoutGlobalScopes()->whereIn('lot_id', $ids)->groupBy('lot_id')->selectRaw('lot_id, sum(quantite) as total')->pluck('total', 'lot_id');
        $suivi = LotAllocation::withoutGlobalScopes()->join('mouvements_stock', 'mouvements_stock.id', '=', 'lot_allocations.mouvement_stock_id')
            ->whereIn('lot_allocations.lot_id', $ids)->where('mouvements_stock.source_type', (new MouvementStupefiant)->getMorphClass())
            ->groupBy('lot_allocations.lot_id')->selectRaw('lot_allocations.lot_id, sum(lot_allocations.quantity) as total')->pluck('total', 'lot_id');

        return collect($lots)->mapWithKeys(fn (Lot $lot): array => [$lot->id => max(0.0, round((int) $lot->quantite - (int) ($suivi[$lot->id] ?? 0) + (float) ($inscrit[$lot->id] ?? 0), 3))])->all();
    }

    /**
     * Le contenu en ml d'un lot, en SQL (pour filtrer la liste) : sa quantité,
     * plus ce que le stock en a sorti pour le registre, moins ce que le
     * registre y a écrit. Ses liaisons : la classe des lignes du registre.
     */
    public static function contenuEnSql(): string
    {
        return '(lots.quantite'
            .' - (select coalesce(sum(a.quantity), 0) from lot_allocations a join mouvements_stock m on m.id = a.mouvement_stock_id where a.lot_id = lots.id and m.source_type = ?)'
            .' + (select coalesce(sum(ms.quantite), 0) from mouvements_stupefiants ms where ms.lot_id = lots.id))';
    }

    /**
     * La date d'une ligne (01.11.2026) : celle du bulletin d'une réception (art. 57, al. 3 OCStup), sinon le jour de
     * l'inscription, à l'heure de la clinique ; le bilan d'une période se fait sur elle. Un bulletin du jour du dernier
     * visa ou inventaire, ou plus ancien, date la ligne du jour de l'inscription : une période visée ou un stock
     * compté ne bouge jamais, et le bulletin reste écrit avec sa date. Lu sous le verrou de la substance.
     *
     * @param  array<string, mixed>  $details
     */
    private function dateDeLaLigne(Produit $produit, TypeMouvementStupefiant $type, array $details, int $cliniqueId): string
    {
        $aujourdhui = aujourdhui(Clinique::query()->find($cliniqueId))->toDateString();
        $bulletinLe = $details['bulletin_le'] ?? null;
        if ($type !== TypeMouvementStupefiant::Reception || ! filled($bulletinLe)) {
            return $aujourdhui;
        }
        $arret = self::lastCheckpoint($produit->id, $cliniqueId);

        return $arret !== null && (string) $bulletinLe <= $arret->toDateString() ? $aujourdhui : (string) $bulletinLe;
    }

    /** Réception d'une commande fournisseur (entrée) */
    public function inscrire(Produit $produit, float $quantite, string $motif, int $cliniqueId, ?User $auteur, array $details = []): MouvementStupefiant
    {
        return $this->ecrire($produit, TypeMouvementStupefiant::Reception, $quantite, ['motif' => $motif, ...$details], $cliniqueId, $auteur);
    }

    /**
     * Élimination d'un lot périmé (24.10.2026) : la quantité du lot peut
     * dépasser ce que le registre compte encore (un lot d'avant le décompte
     * des lots, des doses en dixièmes de ml). Est éliminé le moins des deux,
     * inscrit sur ce lot ; rien s'il ne reste rien (null).
     */
    public function detruire(Produit $produit, float $auPlus, string $motif, int $cliniqueId, ?User $auteur, ?Lot $lot = null): ?MouvementStupefiant
    {
        $solde = max(0.0, $this->soldeVerrouille($produit, $cliniqueId));
        // Le lot écarté s'élimine pour ce qu'il contient en ml, sans déborder sur un autre lot
        $auPlus = $lot !== null ? ($this->contenuDesLots($produit->id, $cliniqueId)[1][$lot->id] ?? 0.0) : $auPlus;
        $quantite = round(min($auPlus, $solde), 3);

        return $quantite > 0 ? $this->ecrire($produit, TypeMouvementStupefiant::Elimination, -$quantite, ['motif' => $motif, 'lot' => $lot], $cliniqueId, $auteur) : null;
    }

    /** Le stock de la substance au registre, la substance verrouillée : une inscription à la fois. */
    public function soldeVerrouille(Produit $produit, int $cliniqueId): float
    {
        Produit::query()->whereKey($produit->id)->lockForUpdate()->first();

        // Une lecture verrouillante : sous MySQL (REPEATABLE READ), une somme ordinaire
        // lirait l'instantané du début de la transaction, sans les lignes écrites
        // entre-temps par une autre (vérification du 01.11.2026)
        return round((float) MouvementStupefiant::withoutGlobalScopes()
            ->where('clinique_id', $cliniqueId)
            ->where('produit_id', $produit->id)
            ->lockForUpdate()
            ->sum('quantite'), 3);
    }

    /**
     * Le jour du dernier arrêt du registre d'une substance dans une clinique (visa ou inventaire), à l'heure de la
     * clinique ; null sans arrêt. Une ligne datée avant lui changerait un stock visé ou compté (01.11.2026).
     */
    public static function lastCheckpoint(int $produitId, int $cliniqueId): ?CarbonImmutable
    {
        $quand = MouvementStupefiant::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->where('produit_id', $produitId)
            // Lecture verrouillante : sous MySQL, une lecture ordinaire verrait l'instantané du début de la transaction,
            // sans un visa inscrit entre-temps (vérification du 01.11.2026)
            ->whereIn('type', [TypeMouvementStupefiant::Visa, TypeMouvementStupefiant::Inventaire])->lockForUpdate()->max('created_at');
        if ($quand === null) {
            return null;
        }
        $fuseau = Clinique::query()->find($cliniqueId)->fuseau_horaire ?? 'Europe/Zurich';

        return CarbonImmutable::parse($quand, 'UTC')->setTimezone($fuseau)->startOfDay();
    }

    /** « 12,5 » : la quantité écrite à la suisse romande, sans zéros inutiles */
    public static function quantite(float $q): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'));
    }
}
