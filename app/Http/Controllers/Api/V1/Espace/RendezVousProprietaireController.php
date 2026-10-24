<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Espace\RendezVousProprietaireResource;
use App\Models\DemandeRendezVous;
use App\Models\RendezVous;
use App\Services\MutationRendezVous;
use App\Services\Paiements\AcomptesRendezVous;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mes rendez-vous : à venir et passés, toutes cliniques ; annulation
 * par le propriétaire (auditée à son nom, comme un geste de clinique),
 * ou, avec un acompte payé, demande d'annulation que la clinique tranche
 * (l'acompte n'est jamais rendu d'office) ; demande de report, déposée
 * dans la file des demandes de la clinique (« la clinique vous
 * recontacte »).
 */
class RendezVousProprietaireController extends Controller
{
    private const int PASSES_MAX = 30;

    public function index(ContexteProprietaire $contexte): JsonResponse
    {
        $base = fn () => RendezVous::withoutGlobalScopes()
            ->with(['clinique', 'lignePlanning', 'patient', 'demandeEnLigne.clinique'])
            ->where(fn ($q) => $this->miens($q, $contexte))
            ->where('type', '!=', 'tampon');

        $aVenir = $base()
            ->whereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        $passes = $base()
            ->where(fn ($q) => $q->where('ends_at', '<', now())
                ->orWhereIn('status', [StatutRendezVous::Fini, StatutRendezVous::Noshow, StatutRendezVous::Annule]))
            ->orderByDesc('starts_at')
            ->limit(self::PASSES_MAX)
            ->get();

        return response()->json(['data' => [
            'a_venir' => RendezVousProprietaireResource::collection($aVenir)->resolve(),
            'passes' => RendezVousProprietaireResource::collection($passes)->resolve(),
        ]]);
    }

    public function annuler(Request $request, ContexteProprietaire $contexte, MutationRendezVous $mutation, int $rendezVousId): RendezVousProprietaireResource
    {
        $rendezVous = $this->mien($contexte, $rendezVousId);

        if ($rendezVous->status !== StatutRendezVous::Prevu || ! $rendezVous->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous ne peut plus être annulé en ligne : contactez la clinique.']);
        }
        if (($rendezVous->demandeEnLigne()->first()?->acompteRetenu() ?? 0) > 0) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous a un acompte : demandez l’annulation à la clinique, elle décide de le rendre ou non.']);
        }

        $mutation->appliquer(
            $rendezVous,
            ['status' => StatutRendezVous::Annule],
            $request->user(),
            entreesSupplementaires: ['motifAnnulation' => [null, 'Annulé par le propriétaire depuis l’espace client']],
        );

        return new RendezVousProprietaireResource($rendezVous->refresh()->load(['clinique', 'lignePlanning', 'patient', 'demandeEnLigne.clinique']));
    }

    /** Rendez-vous avec acompte : la demande d'annulation part à la clinique, qui décide. */
    public function demanderAnnulation(Request $request, ContexteProprietaire $contexte, AcomptesRendezVous $acomptes, int $rendezVousId): RendezVousProprietaireResource
    {
        $motif = $request->validate(['motif' => ['nullable', 'string', 'max:500']])['motif'] ?? null;
        $rendezVous = $this->mien($contexte, $rendezVousId);

        if ($rendezVous->status !== StatutRendezVous::Prevu || ! $rendezVous->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous ne peut plus être annulé en ligne : contactez la clinique.']);
        }
        $acomptes->demanderAnnulation($rendezVous, $motif !== null ? trim($motif) : null);

        return new RendezVousProprietaireResource($rendezVous->refresh()->load(['clinique', 'lignePlanning', 'patient', 'demandeEnLigne.clinique']));
    }

    public function reporter(ContexteProprietaire $contexte, int $rendezVousId): JsonResponse
    {
        $rendezVous = $this->mien($contexte, $rendezVousId);

        if ($rendezVous->status !== StatutRendezVous::Prevu || ! $rendezVous->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous ne peut plus être reporté en ligne, contactez la clinique.']);
        }

        $souhait = 'Report demandé par le propriétaire (rendez-vous du '
            .$rendezVous->starts_at->setTimezone($rendezVous->clinique->fuseau_horaire ?? 'Europe/Zurich')->format('d.m.Y à H:i').')';
        // Un report à la fois pour ce rendez-vous, et le plafond des demandes (24.10.2026)
        $dejaDemande = DemandeRendezVous::withoutGlobalScopes()
            ->where('clinique_id', $rendezVous->clinique_id)
            ->where('user_id', $contexte->user()->id)
            ->where('souhait', $souhait)
            ->whereIn('statut', [StatutDemandeRendezVous::ATraiter, StatutDemandeRendezVous::Proposee])
            ->exists();
        if ($dejaDemande) {
            throw ValidationException::withMessages(['status' => 'Le report de ce rendez-vous est déjà demandé : la clinique vous répond.']);
        }
        CliniqueProprietaireController::plafond($rendezVous->clinique, fn ($q) => $q->where('user_id', $contexte->user()->id));

        $demande = DemandeRendezVous::withoutGlobalScopes()->create([
            'clinique_id' => $rendezVous->clinique_id,
            'patient_id' => $rendezVous->patient_id,
            'client_id' => $rendezVous->client_id,
            'user_id' => $contexte->user()->id,
            'patient_name' => $rendezVous->patient_name,
            'species' => $rendezVous->species,
            'owner_name' => $rendezVous->owner_name,
            'reason' => 'Report : '.($rendezVous->reason ?: 'rendez-vous'),
            'souhait' => $souhait,
            'mode' => ModeDemandeRendezVous::AValider,
            'duree_minutes' => max(15, (int) $rendezVous->starts_at->diffInMinutes($rendezVous->ends_at)),
        ]);

        return response()->json(['data' => [
            'demande_id' => $demande->id,
            'clinique' => ['id' => $rendezVous->clinique->id, 'nom' => $rendezVous->clinique->nom],
        ]], 201);
    }

    private function mien(ContexteProprietaire $contexte, int $rendezVousId): RendezVous
    {
        return RendezVous::withoutGlobalScopes()
            ->with(['clinique', 'lignePlanning', 'patient'])
            ->where(fn ($q) => $this->miens($q, $contexte))
            ->findOrFail($rendezVousId);
    }

    /**
     * Les rendez-vous du compte : ses fiches et ses animaux dans les
     * cliniques, et ceux pris par ses demandes en ligne acceptées (un
     * animal du compte n'est pas forcément une fiche de la clinique).
     *
     * @param  Builder<RendezVous>  $requete
     */
    private function miens(Builder $requete, ContexteProprietaire $contexte): void
    {
        $requete->whereIn('client_id', $contexte->clientIds())
            ->orWhereIn('patient_id', $contexte->patientIds())
            ->orWhereIn('id', DemandeProprietaireController::miennes($contexte)->whereNotNull('rendez_vous_id')->select('rendez_vous_id'));
    }
}
