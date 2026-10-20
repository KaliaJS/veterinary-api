<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Services\PremiersPas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Premiers pas de la clinique du jeton (administrateurs) : l'état de
 * chaque étape, et les gestes « c'est bon » et « masquer la liste ».
 */
class PremiersPasController extends Controller
{
    public function afficher(ContexteClinique $contexte, PremiersPas $premiersPas): JsonResponse
    {
        return response()->json(['data' => $premiersPas->etat($contexte->clinique())]);
    }

    public function modifier(Request $request, ContexteClinique $contexte, PremiersPas $premiersPas): JsonResponse
    {
        $donnees = $request->validate([
            'confirmer' => ['sometimes', 'string', Rule::in(array_keys(PremiersPas::ETAPES))],
            'masque' => ['sometimes', 'boolean'],
        ]);
        $clinique = $contexte->clinique();

        if (isset($donnees['confirmer'])) {
            $premiersPas->confirmer($clinique, $donnees['confirmer']);
        }
        if (isset($donnees['masque'])) {
            $premiersPas->masquer($clinique->societe->organisation, $donnees['masque']);
        }

        return response()->json(['data' => $premiersPas->etat($clinique->refresh())]);
    }
}
