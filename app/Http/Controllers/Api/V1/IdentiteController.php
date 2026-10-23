<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Abonnements\AbonnementSaas;
use App\Services\Inscription\VerificationIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification d'identité du compte par Stripe Identity (23.10.2026) :
 * l'état, le paiement du badge (2 CHF, sauf pour qui a inscrit une
 * clinique : sur la facture de l'organisation) et l'adresse de la page
 * de Stripe où montrer sa pièce.
 */
class IdentiteController extends Controller
{
    public function __construct(private VerificationIdentite $identite) {}

    public function afficher(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identite->etat($request->user())]);
    }

    public function payer(Request $request, AbonnementSaas $abonnements): JsonResponse
    {
        $reglement = $abonnements->acheterVerificationIdentite($request->user());

        return response()->json(['data' => [...$this->identite->etat($request->user()->refresh()), 'paiement' => $reglement->action()]]);
    }

    public function commencer(Request $request): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->identite->demarrer($request->user())]]);
    }
}
