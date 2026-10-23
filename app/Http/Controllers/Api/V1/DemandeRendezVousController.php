<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DecisionAnnulation;
use App\Enums\MotifRefusDemande;
use App\Enums\StatutDemandeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\FilesAttente\AccepterDemandeRequest;
use App\Http\Requests\V1\FilesAttente\DeciderAnnulationRequest;
use App\Http\Requests\V1\FilesAttente\ProposerCreneauxRequest;
use App\Http\Requests\V1\FilesAttente\RefuserDemandeRequest;
use App\Http\Resources\V1\DemandeRendezVousResource;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\DemandeRendezVous;
use App\Services\CalculateurCreneaux;
use App\Services\Paiements\AcomptesRendezVous;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Demandes de rendez-vous en ligne, côté clinique (cloche du planning).
 * Les demandes ouvertes (à traiter, horaires proposés en attente du
 * propriétaire) et celles traitées aujourd'hui. Accepter crée le
 * rendez-vous à l'heure demandée (ou à une autre heure, sur une ligne
 * donnée) ; proposer envoie d'autres horaires ; refuser donne un motif.
 * Chaque réponse est écrite au propriétaire (TraitementDemande). S'y
 * ajoutent les demandes d'annulation des rendez-vous réservés avec
 * acompte : la clinique annule (en rendant tout, moins les frais, ou
 * rien) ou maintient le rendez-vous.
 */
class DemandeRendezVousController extends Controller
{
    public function index(Request $request, CalculateurCreneaux $calculateur): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DemandeRendezVous::class);

        $ouvertes = array_map(fn (StatutDemandeRendezVous $statut): string => $statut->value, array_filter(
            StatutDemandeRendezVous::cases(),
            fn (StatutDemandeRendezVous $statut): bool => $statut->estOuverte(),
        ));

        return DemandeRendezVousResource::collection(
            DemandeRendezVous::query()
                ->with(['user:id,email', 'rendezVous:id,starts_at'])
                ->where(fn ($requete) => $requete
                    ->whereIn('statut', $ouvertes)
                    ->orWhere('traitee_le', '>=', $calculateur->aujourdHui()->utc())
                    ->orWhere(fn ($annulation) => $annulation->whereNotNull('annulation_demandee_le')->whereNull('annulation_traitee_le'))
                    ->orWhere('annulation_traitee_le', '>=', $calculateur->aujourdHui()->utc()))
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate($this->parPage($request)),
        );
    }

    /**
     * Accepte la demande : à l'heure donnée (sur la ligne donnée, glissée
     * sur la grille, sinon une ligne libre), ou sans heure à celle que
     * le propriétaire a demandée.
     */
    public function placer(AccepterDemandeRequest $request, DemandeRendezVous $demande, TraitementDemande $traitement): RendezVousResource
    {
        $this->authorize('placer', $demande);

        $donnees = $request->validated();
        $debut = ! empty($donnees['starts_at']) ? CarbonImmutable::parse($donnees['starts_at']) : $demande->debut_souhaite;

        if ($debut === null) {
            throw ValidationException::withMessages(['starts_at' => 'La demande n’indique pas d’heure : choisissez-en une.']);
        }

        $rendezVous = $traitement->accepter(
            $demande,
            $debut,
            isset($donnees['ligne_planning_id']) ? (int) $donnees['ligne_planning_id'] : null,
            $request->user(),
        );

        return new RendezVousResource($rendezVous);
    }

    /** Le propriétaire demande l'annulation (acompte payé) : la clinique décide. */
    public function annulation(DeciderAnnulationRequest $request, DemandeRendezVous $demande, AcomptesRendezVous $acomptes): DemandeRendezVousResource
    {
        $this->authorize('placer', $demande);

        $acomptes->deciderAnnulation($demande, DecisionAnnulation::from($request->validated('decision')), $request->user());

        return new DemandeRendezVousResource($demande->refresh()->load(['user:id,email', 'rendezVous:id,starts_at']));
    }

    public function proposer(ProposerCreneauxRequest $request, DemandeRendezVous $demande, TraitementDemande $traitement): DemandeRendezVousResource
    {
        $this->authorize('placer', $demande);

        $donnees = $request->validated();
        $traitement->proposer(
            $demande,
            array_map(fn (string $debut): CarbonImmutable => CarbonImmutable::parse($debut), array_values($donnees['creneaux'])),
            $donnees['message'] ?? null,
        );

        return new DemandeRendezVousResource($demande->refresh());
    }

    public function refuser(RefuserDemandeRequest $request, DemandeRendezVous $demande, TraitementDemande $traitement): DemandeRendezVousResource
    {
        $this->authorize('placer', $demande);

        $donnees = $request->validated();
        $traitement->refuser($demande, MotifRefusDemande::from($donnees['motif']), $donnees['message'] ?? null);

        return new DemandeRendezVousResource($demande->refresh());
    }

    /** Retire une demande sans répondre (doublon, demande farfelue). */
    public function destroy(DemandeRendezVous $demande): Response
    {
        $this->authorize('delete', $demande);

        $demande->delete();

        return response()->noContent();
    }
}
