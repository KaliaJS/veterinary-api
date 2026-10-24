<?php

namespace App\Services\Paiements;

use App\Enums\DomainePermission;
use App\Enums\StatutPaiement;
use App\Enums\TypeAlerte;
use App\Mail\PaiementConteste;
use App\Models\EvenementStripe;
use App\Models\Paiement;
use App\Paiements\RefusCarte;
use App\Services\Abonnements\AbonnementSaas;
use App\Services\Alertes\DestinatairesAlertes;
use App\Services\Inscription\VerificationIdentite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Événements Stripe utiles à la caisse et aux prélèvements de la
 * plateforme (abonnements maison, achats ; plus de Stripe Billing). Chaque
 * événement n'est traité qu'une fois (id verrouillé dans
 * `evenements_stripe`) ; un traitement en échec libère le verrou pour
 * que Stripe réessaie. Une intention ou un client inconnu (autre
 * application sur le même compte) est ignoré sans erreur, pour que Stripe
 * ne le renvoie pas en boucle.
 */
class WebhooksStripe
{
    public function __construct(
        private PaiementsCaisse $paiements,
        private AbonnementSaas $abonnements,
        private AcomptesRendezVous $acomptes,
        private ComptesPaiement $comptes,
        private DestinatairesAlertes $destinataires,
        private VerificationIdentite $identite,
    ) {}

    /** @param  array{id: string, type: string, objet: array<string, mixed>, compte?: ?string}  $evenement */
    public function traiter(array $evenement): void
    {
        $nouveau = EvenementStripe::query()->insertOrIgnore([
            'id' => $evenement['id'],
            'type' => $evenement['type'],
            'traite_le' => now(),
        ]);

        if ($nouveau === 0) {
            return;
        }

        $objet = $evenement['objet'];

        try {
            match ($evenement['type']) {
                'payment_intent.succeeded' => $this->intention($objet, 'succeeded', $evenement['compte'] ?? null),
                'payment_intent.payment_failed' => $this->intention($objet, 'requires_payment_method', $evenement['compte'] ?? null),
                'payment_intent.canceled' => $this->intention($objet, 'canceled', $evenement['compte'] ?? null),
                // Remboursement et litige faits chez Stripe (composant intégré, banque du client)
                'charge.refunded' => $this->rembourse($objet, $evenement['compte'] ?? null),
                'charge.dispute.created' => $this->litige($objet, $evenement['compte'] ?? null, ouvert: true),
                'charge.dispute.closed' => $this->litige($objet, $evenement['compte'] ?? null, ouvert: false),
                'terminal.reader.action_failed' => $this->echecLecteur($objet, $evenement['compte'] ?? null),
                // Carte remplacée par la banque, mise à jour par Stripe
                'payment_method.automatically_updated' => $this->abonnements->surCarteMiseAJour($objet),
                // Compte de paiement d'une société : activé, restreint, informations demandées
                'account.updated' => $this->comptes->surCompteModifie($objet['id'] ?? $evenement['compte'] ?? null),
                // Vérification d'identité, sur le compte de la plateforme seulement
                'identity.verification_session.verified',
                'identity.verification_session.requires_input' => ($evenement['compte'] ?? null) === null && isset($objet['id'])
                    ? $this->identite->surSession((string) $objet['id'])
                    : null,
                default => null,
            };
        } catch (Throwable $e) {
            EvenementStripe::query()->whereKey($evenement['id'])->delete();

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $intention */
    private function intention(array $intention, string $statut, ?string $compte = null): void
    {
        // Acompte d'une réservation en ligne : payé, l'heure est réservée
        if (($intention['metadata']['objet'] ?? null) === 'acompte_rdv') {
            if ($statut === 'succeeded') {
                $this->acomptes->surPaiementReussi($intention['id'], $compte);
            }

            return;
        }

        // Prélèvement de la plateforme (abonnement, pack, objet de la planète)
        if (($intention['metadata']['plateforme'] ?? null) === 'veterinary') {
            $this->abonnements->surPrelevement($intention);

            return;
        }

        $paiement = $this->paiementDuCompte($intention['id'] ?? null, $compte);

        if ($paiement) {
            $this->paiements->appliquer(
                $paiement,
                $statut,
                isset($intention['last_payment_error']) || $statut === 'requires_payment_method' ? RefusCarte::message($intention['last_payment_error'] ?? null) : null,
            );
        }
    }

    /** @param  array<string, mixed>  $lecteur */
    private function echecLecteur(array $lecteur, ?string $compte): void
    {
        $action = $lecteur['action'] ?? [];
        $paiement = $this->paiementDuCompte($action['process_payment_intent']['payment_intent'] ?? null, $compte);

        if ($paiement) {
            // Le code d'échec seulement : le message de Stripe est en anglais
            $this->paiements->echecSurLecteur($paiement, isset($action['failure_code']) ? RefusCarte::message(['failure_code' => $action['failure_code']]) : null);
        }
    }

    /**
     * Le paiement de cette intention, seulement si l'événement vient du
     * compte qui l'a reçu (23.10.2026) : un compte n'agit jamais sur le
     * paiement d'un autre.
     */
    private function paiementDuCompte(?string $intentionId, ?string $compte): ?Paiement
    {
        $paiement = $this->paiement($intentionId);
        if ($paiement && $compte !== null && $paiement->stripe_compte_id !== null && $paiement->stripe_compte_id !== $compte) {
            Log::warning('Événement Stripe d’un autre compte que celui du paiement : ignoré.', ['paiement' => $paiement->id]);

            return null;
        }

        return $paiement;
    }

    /**
     * Remboursé chez Stripe : en entier, le paiement passe « remboursé » ;
     * en partie, la somme rendue est notée. La facture reste payée (un
     * geste commercial, une erreur de saisie) : l'alerte de la caisse le
     * signale à qui tient la facturation.
     *
     * @param  array<string, mixed>  $charge
     */
    private function rembourse(array $charge, ?string $compte): void
    {
        $paiement = $this->paiementDuCompte($charge['payment_intent'] ?? null, $compte);
        if (! $paiement) {
            $this->acomptes->surRemboursementChezStripe($charge['payment_intent'] ?? null, $compte, (int) ($charge['amount_refunded'] ?? 0));

            return;
        }

        $rendu = chf(((int) ($charge['amount_refunded'] ?? 0)) / 100);
        $paiement->update(($charge['refunded'] ?? false)
            ? ['statut' => StatutPaiement::Rembourse, 'erreur' => 'Remboursé ('.$rendu.').']
            : ['erreur' => 'Remboursé en partie : '.$rendu.'.']);
    }

    /**
     * Contesté par le titulaire de la carte : le paiement passe en litige ;
     * clos, il redevient réussi si la clinique a gagné, sinon remboursé.
     *
     * @param  array<string, mixed>  $litige
     */
    private function litige(array $litige, ?string $compte, bool $ouvert): void
    {
        $paiement = $this->paiementDuCompte($litige['payment_intent'] ?? null, $compte);
        if (! $paiement) {
            // Un acompte payé en ligne, peut-être
            $this->acomptes->surLitige($litige['payment_intent'] ?? null, $compte, $ouvert, $litige['status'] ?? null, $litige['evidence_details']['due_by'] ?? null);

            return;
        }

        if ($ouvert) {
            $paiement->update(['statut' => StatutPaiement::Litige, 'erreur' => 'Contesté par le titulaire de la carte'.(isset($litige['reason']) ? ' ('.$litige['reason'].')' : '').'.']);
            $this->prevenirDuLitige($paiement, $litige['evidence_details']['due_by'] ?? null);

            return;
        }

        // Gagné, ou simple demande d'information close sans retrait d'argent
        $paiement->update(in_array($litige['status'] ?? null, ['won', 'warning_closed'], true)
            ? ['statut' => StatutPaiement::Reussi, 'erreur' => null]
            : ['statut' => StatutPaiement::Rembourse, 'erreur' => 'Litige perdu : le montant a été rendu au titulaire de la carte.']);
    }

    /**
     * Les personnes de la facturation sont prévenues tout de suite : la
     * banque laisse quelques jours pour répondre. Un e-mail qui échoue ne
     * fait pas échouer l'événement (l'alerte reste dans l'app).
     */
    private function prevenirDuLitige(Paiement $paiement, mixed $echeance): void
    {
        $paiement->loadMissing(['clinique', 'facture' => fn ($requete) => $requete->withoutGlobalScopes()]);
        if (! $paiement->clinique || ! $paiement->facture) {
            return;
        }

        $destinataires = $this->destinataires->pour($paiement->clinique, TypeAlerte::Paiement, DomainePermission::Facturation);
        if ($destinataires === []) {
            return;
        }

        $date = is_numeric($echeance)
            ? CarbonImmutable::createFromTimestamp((int) $echeance, $paiement->clinique->fuseau_horaire ?: 'Europe/Zurich')->format('d.m.Y à H:i')
            : null;

        try {
            Mail::to($destinataires)->send(new PaiementConteste($paiement, $date));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function paiement(?string $intentionId): ?Paiement
    {
        return $intentionId
            ? Paiement::withoutGlobalScopes()->where('stripe_payment_intent_id', $intentionId)->first()
            : null;
    }
}
