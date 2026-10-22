<?php

namespace App\Services\Paiements;

use App\Models\EvenementStripe;
use App\Models\Paiement;
use App\Services\Abonnements\AbonnementSaas;
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
    public function __construct(private PaiementsCaisse $paiements, private AbonnementSaas $abonnements, private AcomptesRendezVous $acomptes) {}

    /** @param  array{id: string, type: string, objet: array<string, mixed>}  $evenement */
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
                'payment_intent.succeeded' => $this->intention($objet, 'succeeded'),
                'payment_intent.payment_failed' => $this->intention($objet, 'requires_payment_method'),
                'payment_intent.canceled' => $this->intention($objet, 'canceled'),
                'terminal.reader.action_failed' => $this->echecLecteur($objet),
                // Carte remplacée par la banque, mise à jour par Stripe
                'payment_method.automatically_updated' => $this->abonnements->surCarteMiseAJour($objet),
                default => null,
            };
        } catch (Throwable $e) {
            EvenementStripe::query()->whereKey($evenement['id'])->delete();

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $intention */
    private function intention(array $intention, string $statut): void
    {
        // Acompte d'une réservation en ligne : payé, l'heure est réservée
        if (($intention['metadata']['objet'] ?? null) === 'acompte_rdv') {
            if ($statut === 'succeeded') {
                $this->acomptes->surPaiementReussi($intention['id']);
            }

            return;
        }

        // Prélèvement de la plateforme (abonnement, pack, objet de la planète)
        if (($intention['metadata']['plateforme'] ?? null) === 'veterinary') {
            $this->abonnements->surPrelevement($intention);

            return;
        }

        $paiement = $this->paiement($intention['id'] ?? null);

        if ($paiement) {
            $this->paiements->appliquer(
                $paiement,
                $statut,
                $intention['last_payment_error']['message'] ?? ($statut === 'requires_payment_method' ? 'Paiement refusé.' : null),
            );
        }
    }

    /** @param  array<string, mixed>  $lecteur */
    private function echecLecteur(array $lecteur): void
    {
        $action = $lecteur['action'] ?? [];
        $paiement = $this->paiement($action['process_payment_intent']['payment_intent'] ?? null);

        if ($paiement) {
            $this->paiements->echecSurLecteur($paiement, $action['failure_message'] ?? null);
        }
    }

    private function paiement(?string $intentionId): ?Paiement
    {
        return $intentionId
            ? Paiement::withoutGlobalScopes()->where('stripe_payment_intent_id', $intentionId)->first()
            : null;
    }
}
