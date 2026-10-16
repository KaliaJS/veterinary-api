<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Services\Abonnements\AchatsAppStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Achats dans l'app iPhone (StoreKit 2) : identifiant App Store du compte
 * et produits à demander à l'App Store ; transactions signées envoyées par
 * l'app (achat, renouvellement, restauration), une ou plusieurs à la fois.
 * L'offre et la planète se relisent ensuite par leurs propres routes.
 */
class AppStoreController extends Controller
{
    public function __construct(private AchatsAppStore $achats) {}

    public function afficher(ContexteProprietaire $contexte): JsonResponse
    {
        return response()->json(['data' => [
            'compte' => $this->achats->compte($contexte->user()),
            'produits' => $this->achats->produits(),
        ]]);
    }

    public function transactions(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validate([
            'transactions' => ['required', 'array', 'min:1', 'max:50'],
            'transactions.*' => ['required', 'string', 'max:20000'],
        ]);
        foreach ($donnees['transactions'] as $jws) {
            $this->achats->enregistrer($contexte->user(), $jws);
        }

        return response()->json(['data' => ['enregistrees' => count($donnees['transactions'])]]);
    }
}
