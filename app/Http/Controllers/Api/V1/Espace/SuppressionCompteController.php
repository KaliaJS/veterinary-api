<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Services\Comptes\SuppressionCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppression du compte depuis l'espace (App Store, 27.10.2026) : un code
 * par e-mail, puis la demande ; le compte est effacé 30 jours plus tard.
 */
class SuppressionCompteController extends Controller
{
    public function code(ContexteProprietaire $contexte, SuppressionCompte $suppression): JsonResponse
    {
        $suppression->envoyerCode($contexte->user());

        return response()->json(['data' => ['envoye' => true]], 202);
    }

    public function demander(Request $request, ContexteProprietaire $contexte, SuppressionCompte $suppression): JsonResponse
    {
        $donnees = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $effaceLe = $suppression->demander($contexte->user(), $donnees['code']);

        return response()->json(['data' => ['efface_le' => $effaceLe->toDateString()]]);
    }
}
