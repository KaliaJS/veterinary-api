<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerMoyenPaiementRequest;
use App\Http\Resources\V1\FacturePlateformeResource;
use App\Models\User;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;

/**
 * Offre du propriétaire : tout l'espace client est gratuit ; « Plus »
 * (12 CHF par an, payé d'avance, renouvelé à la date anniversaire) donne
 * d'autres planètes au jeu de l'accueil. Carte saisie dans le Payment
 * Element de Stripe, souscription, retour à Gratuit en fin d'année payée
 * (réactivable d'ici là), factures de la plateforme (sans TVA). Dans l'app
 * iPhone, Plus se paie par l'App Store (AchatsAppStore) : `source` dit qui
 * encaisse. Chaque réponse renvoie l'offre à jour, et `paiement` quand la
 * banque demande une confirmation.
 */
class AbonnementProprietaireController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function afficher(ContexteProprietaire $contexte): JsonResponse
    {
        return $this->offre($contexte->user());
    }

    public function intentionCarte(ContexteProprietaire $contexte): JsonResponse
    {
        return response()->json(['data' => $this->abonnements->intentionCarte($contexte->user(), $contexte->user()->email)]);
    }

    public function enregistrerCarte(EnregistrerMoyenPaiementRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $this->abonnements->enregistrerCarte($contexte->user(), $request->validated('payment_method'));

        return $this->offre($contexte->user());
    }

    public function supprimerCarte(ContexteProprietaire $contexte): JsonResponse
    {
        $this->abonnements->supprimerCarte($contexte->user());

        return $this->offre($contexte->user());
    }

    public function souscrire(ContexteProprietaire $contexte): JsonResponse
    {
        $reglement = $this->abonnements->souscrirePlus($contexte->user());

        return $this->offre($contexte->user(), $reglement?->action());
    }

    /** Retour à Gratuit à la fin de la période payée. */
    public function resilier(ContexteProprietaire $contexte): JsonResponse
    {
        $this->abonnements->resilier($contexte->user());

        return $this->offre($contexte->user());
    }

    public function reactiver(ContexteProprietaire $contexte): JsonResponse
    {
        $this->abonnements->reactiver($contexte->user());

        return $this->offre($contexte->user());
    }

    /** Une facture, à imprimer */
    public function facture(ContexteProprietaire $contexte, int $facture): FacturePlateformeResource
    {
        return (new FacturePlateformeResource($contexte->user()->facturesPlus()->findOrFail($facture)))->avecEntetes();
    }

    public function regler(ContexteProprietaire $contexte, int $facture): JsonResponse
    {
        $reglement = $this->abonnements->regler($contexte->user()->facturesPlus()->findOrFail($facture));

        return $this->offre($contexte->user(), $reglement->action());
    }

    public function confirmer(ContexteProprietaire $contexte, int $facture): JsonResponse
    {
        $reglement = $this->abonnements->confirmer($contexte->user()->facturesPlus()->findOrFail($facture));

        return $this->offre($contexte->user(), $reglement->action());
    }

    /** @param  array<string, mixed>|null  $paiement */
    private function offre(User $user, ?array $paiement = null): JsonResponse
    {
        $user->refresh();

        return response()->json(['data' => [
            'formule' => $user->aPlus() ? 'plus' : 'gratuit',
            // Plus payé sur le site (Stripe) ou dans l'app iPhone (App Store)
            'source' => match (true) {
                $user->aPlusApple() => 'app_store',
                $user->aPlusStripe() => 'stripe',
                default => null,
            },
            'app_store' => $user->aPlusApple() ? [
                'fin' => $user->apple_fin->toIso8601String(),
                'renouvelle' => (bool) $user->apple_renouvelle,
            ] : null,
            'prix_annuel' => AbonnementSaas::PLUS_CENTIMES / 100,
            'statut' => $user->statut_abonnement,
            'periode_debut' => $user->periode_debut?->toDateString(),
            'periode_fin' => $user->periode_fin?->toDateString(),
            'resilie_le' => $user->resilie_le?->toDateString(),
            'prochaine_tentative_le' => $user->prochaine_tentative_le?->toIso8601String(),
            'fermeture_le' => AbonnementSaas::fermetureLe($user)?->toDateString(),
            'carte_expire_le' => $user->carte_expire_le?->toDateString(),
            'moyen_paiement' => $user->moyen_paiement,
            'factures' => FacturePlateformeResource::collection(
                $user->facturesPlus()->where('statut', '!=', 'annulee')->latest('emise_le')->latest('id')->get(),
            )->resolve(),
        ], 'paiement' => $paiement]);
    }
}
