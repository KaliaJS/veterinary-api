<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\TypeMouvementStupefiant;
use App\Models\Clinique;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * L'écriture au registre des stupéfiants (24.10.2026, mise aux normes le
 * 01.11.2026) : une seule porte pour chaque ligne, qu'elle vienne du
 * registre lui-même, de la réception d'une commande ou du retrait d'un
 * lot périmé. La substance est verrouillée le temps de l'écriture : le
 * stock lu reste vrai, une sortie ne le dépasse jamais. Chaque ligne garde
 * le stock après elle et les noms figés (animal, détenteur, signataire).
 * Le stock de l'article suit le registre. À appeler dans une transaction.
 */
class RegistreStupefiants
{
    public function __construct(private GestionnaireStock $stock) {}

    /**
     * @param  float  $quantite  signée (+ entre, − sort ; 0 pour un visa)
     * @param  array<string, mixed>  $details  motif, patient, ordonnance, ordonnance_officielle, ordonnance_officielle_le, fournisseur, bulletin, bulletin_le, corrige_id, quantite_comptee
     */
    public function ecrire(Produit $produit, TypeMouvementStupefiant $type, float $quantite, array $details, int $cliniqueId, ?User $auteur): MouvementStupefiant
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
            $dejaServi = MouvementStupefiant::withoutGlobalScopes()->whereIn('clinique_id', $cliniques)->where('produit_id', $produit->id)
                ->where('type', TypeMouvementStupefiant::Remise)->whereNull('ordonnance_id')->where('ordonnance_numero', $details['ordonnance_officielle'])->exists();
            if ($dejaServi) {
                throw ValidationException::withMessages(['ordonnance_officielle' => 'Ce formulaire officiel a déjà servi à la remise de ce médicament : une ordonnance du tableau a ne se renouvelle pas (art. 51, al. 3 OCStup).']);
            }
        }

        /** @var Patient|null $patient */
        $patient = $details['patient'] ?? null;
        /** @var Ordonnance|null $ordonnance */
        $ordonnance = $details['ordonnance'] ?? null;
        $employe = app(ContexteClinique::class)->employe();

        // La clinique visée peut être une clinique sœur (ajustement de son
        // stock) : posée ici, hors des événements qui imposent celle du jeton
        $mouvement = MouvementStupefiant::withoutEvents(fn (): MouvementStupefiant => MouvementStupefiant::create([
            'clinique_id' => $cliniqueId,
            'produit_id' => $produit->id,
            'type' => $type,
            'patient_id' => $patient?->id,
            'employe_id' => $employe?->id,
            'quantite' => round($quantite, 3),
            'solde_apres' => round($solde + $quantite, 3),
            'corrige_id' => $details['corrige_id'] ?? null,
            'quantite_comptee' => $details['quantite_comptee'] ?? null,
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
            'date_registre' => $this->dateDeLaLigne($produit, $type, $details, $cliniqueId),
            'created_at' => now(),
        ]));

        if ($quantite !== 0.0) {
            $this->stock->alignerSurLeRegistre($produit, $mouvement, $auteur);
        }

        return $mouvement;
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
     * Élimination d'un lot périmé (24.10.2026) : un lot ne baisse pas quand
     * une dose est inscrite au registre, sa quantité peut dépasser ce que le
     * registre compte encore. Est éliminé le moins des deux ; rien s'il ne
     * reste rien (null).
     */
    public function detruire(Produit $produit, float $auPlus, string $motif, int $cliniqueId, ?User $auteur): ?MouvementStupefiant
    {
        $quantite = round(min($auPlus, max(0.0, $this->soldeVerrouille($produit, $cliniqueId))), 3);

        return $quantite > 0 ? $this->ecrire($produit, TypeMouvementStupefiant::Elimination, -$quantite, ['motif' => $motif], $cliniqueId, $auteur) : null;
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
