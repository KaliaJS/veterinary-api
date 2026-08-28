<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\EmetteurJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\ChangerCliniqueRequest;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\JetonApi;

/**
 * Changement de clinique (docs/branchement.md) : le jeton porte la
 * clinique, changer de clinique est une ré-émission de jeton vers un
 * autre rattachement de l'employé — l'ancien jeton est révoqué.
 */
class ChangementCliniqueController extends Controller
{
    public function __invoke(ChangerCliniqueRequest $request, EmetteurJetons $emetteur): JetonEmisResource
    {
        /** @var JetonApi $jeton */
        $jeton = $request->user()->currentAccessToken();

        $resultat = $emetteur->changerClinique($request->user(), $jeton, $request->integer('clinique_id'));

        return new JetonEmisResource($resultat['jeton'], $resultat['employe']);
    }
}
