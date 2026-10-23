<?php

namespace App\Services\Paiements;

use App\Auth\SousContexteClinique;
use App\Enums\DecisionAnnulation;
use App\Enums\DrapeauRendezVous;
use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Mail\AnnulationRendezVousDecidee;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\DemandeRendezVous;
use App\Models\RendezVous;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Services\MutationRendezVous;
use App\Services\TraitementDemande;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Acompte d'une réservation en ligne (contre les rendez-vous non
 * honorés) : la clinique en fixe le montant (cliniques.acompte_centimes).
 * Le client paie dans le parcours (Payment Element) ; payé, l'heure est
 * réservée, ou, prise entre-temps, la demande passe à la clinique avec
 * l'acompte. L'acompte est déduit de la facture du rendez-vous.
 *
 * Il n'est jamais rendu d'office (23.10.2026, règle de Jim : sinon
 * réserver puis annuler ne coûte rien). Le client ne peut plus annuler
 * en ligne : il demande l'annulation, et la clinique décide d'annuler en
 * rendant tout, en rendant moins les frais du paiement, ou en le gardant,
 * ou de maintenir le rendez-vous. Quand c'est la clinique qui annule (ou
 * refuse la demande), elle choisit de même ; rendu en entier par défaut.
 */
class AcomptesRendezVous
{
    public function __construct(
        private ComptesPaiement $comptes,
        private SousContexteClinique $sousContexte,
    ) {}

    /**
     * Ouvre le paiement : l'intention Stripe et ce qu'il faut au navigateur.
     *
     * Le navigateur paie sur le compte de la société (`compte`, option
     * stripeAccount de Stripe.js).
     *
     * @return array{client_secret: string, cle_publique: ?string, compte: string, montant_centimes: int, url_retour: string}
     */
    public function ouvrir(DemandeRendezVous $demande): array
    {
        // Sur le compte de paiement de la société : l'acompte est à elle
        $intention = $this->comptes->passerellePour($demande->clinique_id, pourEncaisser: true)->creerIntentionEnLigne($demande->acompte_centimes, [
            'objet' => 'acompte_rdv',
            'demande_id' => (string) $demande->id,
            'clinique_id' => (string) $demande->clinique_id,
        ], 'acompte-'.$demande->id, ComptesPaiement::commissionEnLigne($demande->acompte_centimes));

        $demande->update(['acompte_intention_id' => $intention['id'], 'acompte_stripe_compte_id' => $this->comptes->compteDe($demande->clinique_id)]);

        return [
            'client_secret' => $intention['client_secret'],
            'cle_publique' => config('services.stripe.key'),
            'compte' => $demande->clinique()->withoutGlobalScopes()->first()?->societe?->stripe_compte_id,
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

            if (! $demande->acompte_intention_id || $demande->acompte_paye_le !== null) {
                return;
            }
            if ($this->comptes->passerellePourCompte($demande->acompte_stripe_compte_id, $demande->clinique_id)->lireIntention($demande->acompte_intention_id)['statut'] !== 'succeeded') {
                return;
            }

            // Payé après la réponse de la clinique (refusée entre-temps) :
            // rien ne se réserve, l'acompte est rendu (23.10.2026)
            if ($demande->statut !== StatutDemandeRendezVous::APayer) {
                $demande->update(['acompte_paye_le' => now(), 'acompte_frais_centimes' => $this->frais($demande)]);
                $this->rembourser($demande);

                return;
            }

            $demande->update(['statut' => StatutDemandeRendezVous::ATraiter, 'acompte_paye_le' => now(), 'acompte_frais_centimes' => $this->frais($demande)]);

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
    public function surPaiementReussi(string $intentionId, ?string $compte = null): void
    {
        $demande = DemandeRendezVous::withoutGlobalScopes()->where('acompte_intention_id', $intentionId)->first();

        // Seulement depuis le compte qui a reçu l'acompte (23.10.2026)
        if ($demande && $compte !== null && $demande->acompte_stripe_compte_id !== null && $demande->acompte_stripe_compte_id !== $compte) {
            return;
        }

        if ($demande) {
            $this->confirmer($demande);
        }
    }

    /**
     * Rend l'acompte payé, une seule fois, selon la décision : tout, ou
     * moins les frais du paiement (rien si les frais dépassent). Vrai si de
     * l'argent est rendu maintenant.
     */
    public function rembourser(DemandeRendezVous $demande, DecisionAnnulation $decision = DecisionAnnulation::Complet): bool
    {
        if (! $demande->acompte_paye_le || $demande->acompte_rembourse_le || ! $demande->acompte_intention_id) {
            return false;
        }

        $montant = match ($decision) {
            DecisionAnnulation::Complet => (int) $demande->acompte_centimes,
            DecisionAnnulation::HorsFrais => max(0, (int) $demande->acompte_centimes - $this->fraisConnus($demande)),
            default => 0,
        };
        if ($montant <= 0) {
            return false;
        }

        $this->comptes->passerellePourCompte($demande->acompte_stripe_compte_id, $demande->clinique_id)->rembourser(
            $demande->acompte_intention_id,
            'remboursement-acompte-'.$demande->id,
            $montant < $demande->acompte_centimes ? $montant : null,
        );
        $demande->update(['acompte_rembourse_le' => now(), 'acompte_rembourse_centimes' => $montant]);

        return true;
    }

    /** Frais du paiement, lus chez le prestataire s'ils ne l'ont pas encore été. */
    public function fraisConnus(DemandeRendezVous $demande): int
    {
        if ($demande->acompte_frais_centimes === null && $demande->acompte_paye_le) {
            $demande->update(['acompte_frais_centimes' => $this->frais($demande)]);
        }
        if ($demande->acompte_frais_centimes === null) {
            throw ValidationException::withMessages(['decision' => 'Les frais du paiement ne sont pas encore connus : rendez tout l’acompte, ou réessayez dans quelques minutes.']);
        }

        return $demande->acompte_frais_centimes;
    }

    /** Le propriétaire demande l'annulation d'un rendez-vous dont l'acompte est payé. */
    public function demanderAnnulation(RendezVous $rendezVous, ?string $motif): DemandeRendezVous
    {
        $demande = $rendezVous->demandeEnLigne()->first();

        if (! $demande || $demande->acompteRetenu() <= 0) {
            throw ValidationException::withMessages(['annulation' => 'Ce rendez-vous n’a pas d’acompte : annulez-le directement.']);
        }
        if ($demande->annulation_demandee_le) {
            throw ValidationException::withMessages(['annulation' => $demande->annulation_traitee_le
                ? 'La clinique a déjà répondu à votre demande d’annulation.'
                : 'Votre demande d’annulation est déjà chez la clinique.']);
        }

        $demande->update(['annulation_demandee_le' => now(), 'annulation_motif' => $motif ?: null]);
        $this->drapeau($rendezVous, true);

        return $demande;
    }

    /**
     * La clinique répond à la demande d'annulation : le rendez-vous est
     * annulé (acompte rendu, rendu moins les frais, ou gardé) ou maintenu.
     * Le propriétaire est prévenu par e-mail.
     */
    public function deciderAnnulation(DemandeRendezVous $demande, DecisionAnnulation $decision, User $auteur): DemandeRendezVous
    {
        if (! $demande->annulationEnAttente()) {
            throw ValidationException::withMessages(['decision' => 'Aucune demande d’annulation en attente.']);
        }
        $rendezVous = RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id);

        DB::transaction(function () use ($demande, $decision, $auteur, $rendezVous): void {
            if ($decision->annule() && $rendezVous && $rendezVous->status !== StatutRendezVous::Annule) {
                app(MutationRendezVous::class)->appliquer(
                    $rendezVous,
                    ['status' => StatutRendezVous::Annule],
                    $auteur,
                    entreesSupplementaires: ['motifAnnulation' => [null, 'Annulation demandée par le propriétaire, acceptée par la clinique']],
                );
                $this->rembourser($demande, $decision);
            }
            if ($rendezVous) {
                $this->drapeau($rendezVous, false);
            }
            $demande->update(['annulation_decision' => $decision, 'annulation_traitee_le' => now()]);
        });

        $adresse = $demande->adresseDuProprietaire();
        if ($adresse) {
            Mail::to($adresse)->send(new AnnulationRendezVousDecidee($demande->refresh()->load('clinique'), $rendezVous?->starts_at));
        }

        return $demande;
    }

    /**
     * La clinique annule elle-même un rendez-vous réservé avec acompte :
     * elle choisit ce qu'elle rend (tout par défaut). Une demande
     * d'annulation en attente est ainsi tranchée.
     */
    public function surAnnulationDeLaClinique(RendezVous $rendezVous, DecisionAnnulation $decision = DecisionAnnulation::Complet): void
    {
        $demande = $rendezVous->demandeEnLigne()->first();

        if (! $demande) {
            return;
        }
        $this->rembourser($demande, $decision);
        if ($demande->annulationEnAttente()) {
            $demande->update(['annulation_decision' => $decision, 'annulation_traitee_le' => now()]);
        }
        $this->drapeau($rendezVous, false);
    }

    private function frais(DemandeRendezVous $demande): ?int
    {
        try {
            return $demande->acompte_intention_id ? $this->comptes->passerellePourCompte($demande->acompte_stripe_compte_id, $demande->clinique_id)->fraisIntention($demande->acompte_intention_id) : null;
        } catch (ErreurPaiement|ValidationException) {
            return null;
        }
    }

    /** Le drapeau « annulation demandée » du rendez-vous, posé ou retiré. */
    private function drapeau(RendezVous $rendezVous, bool $pose): void
    {
        $drapeaux = collect($rendezVous->flags ?? [])->reject(fn ($d) => $d === DrapeauRendezVous::AnnulationDemandee);
        if ($pose) {
            $drapeaux->push(DrapeauRendezVous::AnnulationDemandee);
        }
        $rendezVous->forceFill(['flags' => $drapeaux->values()->all()])->save();
    }
}
