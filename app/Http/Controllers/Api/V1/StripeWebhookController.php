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
 * Signature invalide → 400 ; tout le reste → 204, même un événement
 * ignoré, pour que Stripe ne le renvoie pas.
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

        $webhooks->traiter($evenement);

        return response()->noContent();
    }
}
