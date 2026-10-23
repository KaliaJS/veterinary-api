<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Paiements\WebhooksStripe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook Stripe (public, signé) : issue des paiements de caisse.
 * Signature invalide → 400 ; le prestataire refuse pendant le traitement
 * (un remboursement, une lecture) → 503, l'événement libéré pour que
 * Stripe le renvoie ; tout le reste → 204, même un événement ignoré.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PasserellePaiement $passerelle, WebhooksStripe $webhooks): Response
    {
        try {
            $evenement = $passerelle->lireEvenement($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (ErreurPaiement) {
            return response()->noContent(400);
        }

        try {
            $webhooks->traiter($evenement);
        } catch (ErreurPaiement $e) {
            report($e);

            return response()->noContent(503);
        }

        return response()->noContent();
    }
}
