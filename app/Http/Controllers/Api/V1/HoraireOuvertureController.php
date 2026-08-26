<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Horaires\ModifierHorairesRequest;
use App\Http\Resources\V1\HoraireOuvertureResource;
use App\Models\HoraireOuverture;

/**
 * Une ressource par clinique (celle du jeton), créée aux défauts à la
 * première lecture — des réglages, jamais des constantes.
 */
class HoraireOuvertureController extends Controller
{
    public function afficher(): HoraireOuvertureResource
    {
        $this->authorize('viewAny', HoraireOuverture::class);

        return new HoraireOuvertureResource($this->horaires());
    }

    public function modifier(ModifierHorairesRequest $request): HoraireOuvertureResource
    {
        $this->authorize('update', HoraireOuverture::class);

        $horaires = $this->horaires();
        $horaires->update($request->validated());

        return new HoraireOuvertureResource($horaires);
    }

    /**
     * La création silencieuse aux défauts ne doit pas transformer la
     * réponse en 201 (`wasRecentlyCreated` pilote le statut des
     * ressources).
     */
    private function horaires(): HoraireOuverture
    {
        $horaires = HoraireOuverture::firstOrCreate([], HoraireOuverture::DEFAUTS);
        $horaires->wasRecentlyCreated = false;

        return $horaires;
    }
}
