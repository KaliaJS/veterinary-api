<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Paiements\AppStore\JwsInvalide;
use App\Services\Abonnements\AchatsAppStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Notifications serveur de l'App Store (V2, public, vérifiées par leur
 * signature) : renouvellement de Plus, résiliation, expiration,
 * remboursement d'un achat. Adresse à déclarer dans App Store Connect.
 */
class AppStoreNotificationsController extends Controller
{
    public function __invoke(Request $request, AchatsAppStore $achats): Response
    {
        $charge = (string) $request->input('signedPayload', '');
        try {
            $achats->notification($charge);
        } catch (JwsInvalide|ValidationException) {
            return response('', 400);
        }

        return response('', 200);
    }
}
