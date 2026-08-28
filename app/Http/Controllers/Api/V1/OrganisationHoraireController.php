<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Horaires\ModifierHorairesRequest;
use App\Http\Resources\V1\HoraireOuvertureResource;
use App\Models\Clinique;
use App\Models\HoraireOuverture;

/**
 * Horaires d'une clinique de l'organisation (écran Organisation) :
 * `PUT /horaires` ne vise que la clinique du jeton, l'assistant
 * clinique doit pouvoir régler une clinique nouvellement créée ou une
 * clinique sœur. Geste de STRUCTURE, domaine `organisation` :
 * résolution explicite depuis l'organisation du jeton, hors scope
 * clinique — dérogation documentée à l'invariant d'écriture locale,
 * comme la création des horaires à la naissance d'une clinique.
 */
class OrganisationHoraireController extends Controller
{
    public function afficher(int $clinique): HoraireOuvertureResource
    {
        return new HoraireOuvertureResource($this->horairesDe($this->cliniqueDeLOrganisation($clinique)));
    }

    public function modifier(ModifierHorairesRequest $request, int $clinique): HoraireOuvertureResource
    {
        $horaires = $this->horairesDe($this->cliniqueDeLOrganisation($clinique));

        $horaires->update($request->validated());

        return new HoraireOuvertureResource($horaires);
    }

    private function cliniqueDeLOrganisation(int $id): Clinique
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation
            ->cliniques()
            ->findOrFail($id);
    }

    /**
     * Une clinique créée par l'API naît avec ses horaires ; celles du
     * seed ou d'avant peuvent en manquer — créés aux défauts, sans
     * transformer la réponse en 201.
     */
    private function horairesDe(Clinique $clinique): HoraireOuverture
    {
        $horaires = HoraireOuverture::withoutEvents(fn (): HoraireOuverture => HoraireOuverture::query()
            ->withoutGlobalScope('clinique')
            ->firstOrCreate(['clinique_id' => $clinique->id], HoraireOuverture::DEFAUTS));
        $horaires->wasRecentlyCreated = false;
        $horaires->setRelation('clinique', $clinique);

        return $horaires;
    }
}
