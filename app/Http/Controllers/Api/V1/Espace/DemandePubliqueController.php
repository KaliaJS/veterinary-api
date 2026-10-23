<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\SousContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ChoisirCreneauDemandeRequest;
use App\Http\Resources\V1\Espace\DemandeProprietaireResource;
use App\Models\DemandeRendezVous;
use App\Services\Paiements\AcomptesRendezVous;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;

/**
 * Une demande suivie par son lien (`/demande/{jeton}`) : déposée sans
 * compte (le lien de ses e-mails), ou avec un acompte (retour du
 * paiement). Où elle en est, le choix d'un horaire quand la clinique en
 * propose, la confirmation du paiement de l'acompte.
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

    /** Retour du paiement de l'acompte (dans le parcours, ou après une redirection de la banque ou de TWINT). */
    public function paiement(AcomptesRendezVous $acomptes, string $jeton): DemandeProprietaireResource
    {
        $demande = $acomptes->confirmer($this->parJeton($jeton));

        return new DemandeProprietaireResource($demande->load(['clinique', 'rendezVous']));
    }

    /** Le jeton est secret : il vient de l'e-mail ou du parcours qui a ouvert le paiement. */
    private function parJeton(string $jeton): DemandeRendezVous
    {
        $demande = DemandeRendezVous::withoutGlobalScopes()->where('jeton', DemandeRendezVous::empreinte($jeton))->firstOrFail();

        // Un lien ne sert pas toujours (23.10.2026) : 30 jours après l'heure demandée
        abort_if($demande->lienEstExpire(), 410, 'Ce lien a expiré : votre clinique vous renseigne.');

        return $demande;
    }
}
