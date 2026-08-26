<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerCompteRenduRequest;
use App\Http\Resources\V1\CompteRenduResource;
use App\Models\CompteRendu;
use App\Models\RendezVous;

/**
 * Compte rendu SOAP — un par rendez-vous, jamais résolu directement :
 * le rendez-vous (scoppé clinique) porte l'isolation. Le PUT fait
 * l'upsert, le signataire est l'utilisateur du dernier enregistrement.
 */
class CompteRenduController extends Controller
{
    public function afficher(RendezVous $rendezVous): CompteRenduResource
    {
        $this->authorize('viewAny', CompteRendu::class);

        return new CompteRenduResource(
            $rendezVous->compteRendu()->firstOrFail(),
        );
    }

    public function enregistrer(EnregistrerCompteRenduRequest $request, RendezVous $rendezVous): CompteRenduResource
    {
        $this->authorize('create', CompteRendu::class);

        $compteRendu = $rendezVous->compteRendu()->updateOrCreate([], [
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return new CompteRenduResource($compteRendu);
    }
}
