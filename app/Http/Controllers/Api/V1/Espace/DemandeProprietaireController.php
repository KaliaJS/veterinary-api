<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\StatutDemandeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ChoisirCreneauDemandeRequest;
use App\Http\Resources\V1\Espace\DemandeProprietaireResource;
use App\Models\DemandeRendezVous;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Mes demandes de rendez-vous, toutes cliniques : où elles en sont, et
 * le choix d'un horaire quand la clinique en propose. Sans compte, le
 * même suivi passe par le lien de l'e-mail (DemandePubliqueController).
 */
class DemandeProprietaireController extends Controller
{
    private const int MAX = 30;

    public function index(ContexteProprietaire $contexte): AnonymousResourceCollection
    {
        return DemandeProprietaireResource::collection(
            self::miennes($contexte)
                ->where('statut', '!=', StatutDemandeRendezVous::APayer)
                ->with(['clinique', 'rendezVous'])
                ->latest()
                ->latest('id')
                ->limit(self::MAX)
                ->get(),
        );
    }

    public function choisir(
        ChoisirCreneauDemandeRequest $request,
        ContexteProprietaire $contexte,
        SousContexteClinique $sousContexte,
        TraitementDemande $traitement,
        int $demande,
    ): DemandeProprietaireResource {
        $demande = self::miennes($contexte)->with('clinique')->findOrFail($demande);

        $sousContexte->pour($demande->clinique, fn () => $traitement->choisir(
            $demande,
            CarbonImmutable::parse($request->validated('starts_at')),
            $request->user(),
        ));

        return new DemandeProprietaireResource($demande->refresh()->load(['clinique', 'rendezVous']));
    }

    /**
     * Les demandes du compte : déposées par lui, ou pour ses fiches et
     * ses animaux (un report demandé depuis un rendez-vous). Jamais celles
     * d'un propriétaire précédent de l'animal (décision de Jim, 02.11.2026).
     *
     * @return Builder<DemandeRendezVous>
     */
    public static function miennes(ContexteProprietaire $contexte): Builder
    {
        return DemandeRendezVous::withoutGlobalScopes()->where(fn ($requete) => $requete
            ->where('user_id', $contexte->user()->id)
            ->orWhereIn('client_id', $contexte->clientIds())
            // Pour ses animaux, sauf celles d'un propriétaire précédent (une autre fiche, un autre compte) : 02.11.2026
            ->orWhere(fn ($q) => $q->whereIn('patient_id', $contexte->patientIds())
                ->where(fn ($q) => $q->whereNull('client_id')
                    ->orWhereRaw('demandes_rendez_vous.client_id = (select patients.client_id from patients where patients.id = demandes_rendez_vous.patient_id)'))
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $contexte->user()->id))));
    }
}
