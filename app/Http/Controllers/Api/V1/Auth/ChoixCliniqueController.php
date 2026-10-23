<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\ChoixClinique;
use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Fin d'une connexion multi-clinique : le jeton de choix (usage unique)
 * et la clinique choisie donnent le jeton d'API.
 */
class ChoixCliniqueController extends Controller
{
    public function __invoke(Request $request, EmetteurJetons $emetteur): JetonEmisResource
    {
        $donnees = $request->validate([
            'choix' => ['required', 'string', 'size:48'],
            'clinique_id' => ['required', 'integer'],
        ]);

        $choix = ChoixClinique::prendre($donnees['choix']);
        $user = $choix ? User::query()->find($choix['user_id']) : null;
        if ($user === null) {
            throw ValidationException::withMessages(['choix' => 'Le choix a expiré : reconnectez-vous.']);
        }

        $rattachement = $emetteur->rattachementParmi($emetteur->rattachements($user), (int) $donnees['clinique_id']);
        if ($rattachement === null) {
            throw ValidationException::withMessages(['clinique_id' => 'Clinique inconnue pour ce compte.']);
        }

        return new JetonEmisResource($emetteur->emettrePour($user, $rattachement, $choix['appareil']), $rattachement->employe);
    }
}
