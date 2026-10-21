<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\SousContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ChoisirCreneauDemandeRequest;
use App\Http\Resources\V1\Espace\DemandeProprietaireResource;
use App\Models\DemandeRendezVous;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;

/**
 * Une demande déposée sans compte, suivie par le lien de ses e-mails
 * (`/demande/{jeton}`) : où elle en est, et le choix d'un horaire quand
 * la clinique en propose.
 */
class DemandePubliqueController extends Controller
{
    public function afficher(string $jeton): DemandeProprietaireResource
    {
        return new DemandeProprietaireResource($this->parJeton($jeton)->load(['clinique', 'rendezVous']));
    }

    public function choisir(ChoisirCreneauDemandeRequest $request, SousContexteClinique $sousContexte, TraitementDemande $traitement, string $jeton): DemandeProprietaireResource
    {
        $demande = $this->parJeton($jeton)->load('clinique');

        $sousContexte->pour($demande->clinique, fn () => $traitement->choisir(
            $demande,
            CarbonImmutable::parse($request->validated('starts_at')),
            null,
        ));

        return new DemandeProprietaireResource($demande->refresh()->load(['clinique', 'rendezVous']));
    }

    private function parJeton(string $jeton): DemandeRendezVous
    {
        return DemandeRendezVous::withoutGlobalScopes()->whereNull('user_id')->where('jeton', $jeton)->firstOrFail();
    }
}
