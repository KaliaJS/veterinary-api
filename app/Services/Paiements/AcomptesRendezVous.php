<?php

namespace App\Services\Paiements;

use App\Auth\SousContexteClinique;
use App\Enums\StatutDemandeRendezVous;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\DemandeRendezVous;
use App\Models\RendezVous;
use App\Models\User;
use App\Paiements\PasserellePaiement;
use App\Services\TraitementDemande;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Acompte d'une réservation en ligne (contre les rendez-vous non
 * honorés) : la clinique en fixe le montant et le délai de remboursement
 * (cliniques.acompte_centimes, acompte_delai_heures). Le client paie dans
 * le parcours (Payment Element) ; payé, l'heure est réservée, ou, prise
 * entre-temps, la demande passe à la clinique avec l'acompte. L'acompte
 * est déduit de la facture du rendez-vous ; remboursé si la clinique
 * annule ou refuse, ou si le client annule assez tôt ; gardé sinon (non
 * venu, annulation tardive).
 */
class AcomptesRendezVous
{
    public function __construct(
        private PasserellePaiement $passerelle,
        private SousContexteClinique $sousContexte,
    ) {}

    /**
     * Ouvre le paiement : l'intention Stripe et ce qu'il faut au navigateur.
     *
     * @return array{client_secret: string, cle_publique: ?string, montant_centimes: int, url_retour: string}
     */
    public function ouvrir(DemandeRendezVous $demande): array
    {
        $intention = $this->passerelle->creerIntentionEnLigne($demande->acompte_centimes, [
            'objet' => 'acompte_rdv',
            'demande_id' => (string) $demande->id,
            'clinique_id' => (string) $demande->clinique_id,
        ], 'acompte-'.$demande->id);

        $demande->update(['acompte_intention_id' => $intention['id']]);

        return [
            'client_secret' => $intention['client_secret'],
            'cle_publique' => config('services.stripe.key'),
            'montant_centimes' => $demande->acompte_centimes,
            'url_retour' => $demande->lienDeRetourPaiement(),
        ];
    }

    /**
     * Le paiement vérifié auprès de Stripe : payé, l'heure demandée est
     * réservée ; prise entre-temps, la demande attend la clinique (qui
     * propose une autre heure, l'acompte reste acquis). Sans effet si ce
     * n'est pas payé ou déjà fait (le retour du navigateur et le webhook
     * peuvent arriver tous les deux).
     */
    public function confirmer(DemandeRendezVous $demande, ?User $auteur = null): DemandeRendezVous
    {
        DB::transaction(function () use ($demande, $auteur): void {
            $demande = DemandeRendezVous::withoutGlobalScopes()->with(['clinique', 'user'])->lockForUpdate()->findOrFail($demande->id);

            if ($demande->statut !== StatutDemandeRendezVous::APayer || ! $demande->acompte_intention_id) {
                return;
            }
            if ($this->passerelle->lireIntention($demande->acompte_intention_id)['statut'] !== 'succeeded') {
                return;
            }

            $demande->update(['statut' => StatutDemandeRendezVous::ATraiter, 'acompte_paye_le' => now()]);

            try {
                $this->sousContexte->pour($demande->clinique, fn () => app(TraitementDemande::class)->accepter($demande, $demande->debut_souhaite, null, $auteur));
            } catch (ValidationException) {
                // L'heure a été prise pendant le paiement : la clinique propose
                $adresse = $demande->adresseDuProprietaire();
                if ($adresse) {
                    Mail::to($adresse)->send(new DemandeRendezVousEnvoyee($demande));
                }
            }
        });

        return $demande->refresh();
    }

    /** Webhook `payment_intent.succeeded` d'un acompte. */
    public function surPaiementReussi(string $intentionId): void
    {
        $demande = DemandeRendezVous::withoutGlobalScopes()->where('acompte_intention_id', $intentionId)->first();

        if ($demande) {
            $this->confirmer($demande);
        }
    }

    /** Rembourse l'acompte payé, une seule fois. Vrai s'il l'a été maintenant. */
    public function rembourser(DemandeRendezVous $demande): bool
    {
        if (! $demande->acompte_paye_le || $demande->acompte_rembourse_le || ! $demande->acompte_intention_id) {
            return false;
        }

        $this->passerelle->rembourser($demande->acompte_intention_id, 'remboursement-acompte-'.$demande->id);
        $demande->update(['acompte_rembourse_le' => now()]);

        return true;
    }

    /** Remboursable si le client annule maintenant : assez tôt avant le rendez-vous (délai de la clinique). */
    public function estRemboursable(RendezVous $rendezVous, DemandeRendezVous $demande): bool
    {
        $delai = $demande->clinique?->acompte_delai_heures ?? 24;

        return now()->addHours($delai)->lessThanOrEqualTo($rendezVous->starts_at);
    }

    /**
     * Le client annule : remboursé s'il prévient assez tôt, gardé sinon.
     * Null s'il n'y avait pas d'acompte.
     */
    public function surAnnulationDuProprietaire(RendezVous $rendezVous): ?bool
    {
        $demande = $rendezVous->demandeEnLigne()->with('clinique')->first();

        if (! $demande || $demande->acompteRetenu() <= 0) {
            return null;
        }

        return $this->estRemboursable($rendezVous, $demande) && $this->rembourser($demande);
    }

    /** La clinique annule : l'acompte est rendu. */
    public function surAnnulationDeLaClinique(RendezVous $rendezVous): void
    {
        $demande = $rendezVous->demandeEnLigne()->first();

        if ($demande) {
            $this->rembourser($demande);
        }
    }
}
