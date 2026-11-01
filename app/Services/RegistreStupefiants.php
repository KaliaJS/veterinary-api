<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\TypeMouvementStupefiant;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\User;
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
     * @param  array<string, mixed>  $details  motif, patient, ordonnance, ordonnance_officielle, fournisseur, bulletin, bulletin_le, corrige_id, quantite_comptee
     */
    public function ecrire(Produit $produit, TypeMouvementStupefiant $type, float $quantite, array $details, int $cliniqueId, ?User $auteur): MouvementStupefiant
    {
        $solde = $this->soldeVerrouille($produit, $cliniqueId);
        if ($quantite < 0 && $solde + $quantite < -0.0005) {
            throw ValidationException::withMessages([
                'quantite' => 'Le registre n’en compte que '.self::quantite(max(0, $solde)).' : une sortie ne dépasse jamais le stock.',
            ]);
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
            'fournisseur' => $details['fournisseur'] ?? null,
            'bulletin' => $details['bulletin'] ?? null,
            'bulletin_le' => $details['bulletin_le'] ?? null,
            'created_at' => now(),
        ]));

        if ($quantite !== 0.0) {
            $this->stock->alignerSurLeRegistre($produit, $mouvement, $auteur);
        }

        return $mouvement;
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

    /** « 12,5 » : la quantité écrite à la suisse romande, sans zéros inutiles */
    public static function quantite(float $q): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'));
    }
}
