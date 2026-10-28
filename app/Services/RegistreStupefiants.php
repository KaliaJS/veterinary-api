<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Inscriptions au registre des stupéfiants faites par un autre geste
 * (24.10.2026) : la réception d'une commande (entrée) et le retrait d'un
 * lot périmé (destruction, sans patient). Le signataire est la personne
 * connectée ; le stock suit le registre. À appeler dans une transaction.
 */
class RegistreStupefiants
{
    public function __construct(private GestionnaireStock $stock) {}

    public function inscrire(Produit $produit, float $quantite, string $motif, int $cliniqueId, ?User $auteur): MouvementStupefiant
    {
        $solde = $this->soldeVerrouille($produit, $cliniqueId);
        if ($quantite < 0 && $solde + $quantite < -0.001) {
            throw ValidationException::withMessages([
                'quantite' => 'Le registre n’en compte que '.number_format(max(0, $solde), 2, '.', '’').' : une sortie ne dépasse jamais le solde.',
            ]);
        }

        // La clinique visée peut être une clinique sœur (ajustement de son
        // stock) : posée ici, hors des événements qui imposent celle du jeton
        $mouvement = MouvementStupefiant::withoutEvents(fn (): MouvementStupefiant => MouvementStupefiant::create([
            'clinique_id' => $cliniqueId,
            'produit_id' => $produit->id,
            'patient_id' => null,
            'employe_id' => app(ContexteClinique::class)->employe()?->id,
            'quantite' => $quantite,
            'motif' => $motif,
            'created_at' => now(),
        ]));

        $this->stock->alignerSurLeRegistre($produit, $mouvement, $auteur);

        return $mouvement;
    }

    /**
     * Destruction d'un lot périmé (24.10.2026) : un lot ne baisse pas quand
     * une dose est inscrite au registre, sa quantité peut dépasser ce que le
     * registre compte encore. Est détruit le moins des deux ; rien s'il ne
     * reste rien (null).
     */
    public function detruire(Produit $produit, float $auPlus, string $motif, int $cliniqueId, ?User $auteur): ?MouvementStupefiant
    {
        $quantite = round(min($auPlus, max(0.0, $this->soldeVerrouille($produit, $cliniqueId))), 2);

        return $quantite > 0 ? $this->inscrire($produit, -$quantite, $motif, $cliniqueId, $auteur) : null;
    }

    /** Le solde de la substance, la substance verrouillée : une inscription à la fois, le solde lu reste vrai. */
    private function soldeVerrouille(Produit $produit, int $cliniqueId): float
    {
        Produit::query()->whereKey($produit->id)->lockForUpdate()->first();

        return (float) MouvementStupefiant::withoutGlobalScopes()
            ->where('clinique_id', $cliniqueId)
            ->where('produit_id', $produit->id)
            ->sum('quantite');
    }
}
