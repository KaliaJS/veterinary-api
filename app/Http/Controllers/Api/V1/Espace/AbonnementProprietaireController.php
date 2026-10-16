<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerMoyenPaiementRequest;
use App\Models\FactureProprietaire;
use App\Models\User;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;

/**
 * Offre du propriétaire (16.10.2026) : tout l'espace client est gratuit ;
 * « Plus » (1 CHF par mois TTC) donne d'autres planètes au jeu de
 * l'accueil. Carte saisie dans le Payment Element de Stripe, souscription,
 * retour à Gratuit en fin de période payée (réactivable d'ici là),
 * factures Stripe. Dans l'app iPhone, Plus se paie par l'App Store
 * (AchatsAppStore) : `source` dit qui encaisse. Chaque réponse renvoie
 * l'offre à jour.
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
        $this->abonnements->souscrirePlus($contexte->user());

        return $this->offre($contexte->user());
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

    private function offre(User $user): JsonResponse
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
            'prix_mensuel' => AbonnementSaas::PLUS_CENTIMES / 100,
            'statut' => $user->statut_abonnement,
            'periode_fin' => $user->periode_fin?->toDateString(),
            'resilie_le' => $user->resilie_le?->toDateString(),
            'moyen_paiement' => $user->moyen_paiement,
            'factures' => $user->facturesPlus()->latest('emise_le')->latest('id')->get()->map(fn (FactureProprietaire $f): array => [
                'id' => $f->id,
                'numero' => $f->numero,
                'emise_le' => $f->emise_le->toDateString(),
                'libelle' => $f->libelle,
                'statut' => $f->statut,
                'montant' => (float) $f->montant,
                'montant_ht' => $f->montant_ht === null ? null : (float) $f->montant_ht,
                'tva' => $f->tva === null ? null : (float) $f->tva,
                'pdf_url' => $f->pdf_url,
                'paiement_url' => $f->paiement_url,
            ])->all(),
        ]]);
    }
}
