<?php

namespace App\Services\Paiements;

use App\Models\EvenementStripe;
use App\Models\Paiement;

/**
 * Événements Stripe utiles à la caisse. Chaque événement n'est traité
 * qu'une fois (id verrouillé dans `evenements_stripe`) ; une intention
 * inconnue (autre application sur le même compte) est ignorée sans
 * erreur, pour que Stripe ne la renvoie pas en boucle.
 */
class WebhooksStripe
{
    public function __construct(private PaiementsCaisse $paiements) {}

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

        match ($evenement['type']) {
            'payment_intent.succeeded' => $this->intention($objet, 'succeeded'),
            'payment_intent.payment_failed' => $this->intention($objet, 'requires_payment_method'),
            'payment_intent.canceled' => $this->intention($objet, 'canceled'),
            'terminal.reader.action_failed' => $this->echecLecteur($objet),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $intention */
    private function intention(array $intention, string $statut): void
    {
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
