<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Espace\RendezVousProprietaireResource;
use App\Models\DemandeRendezVous;
use App\Models\RendezVous;
use App\Services\MutationRendezVous;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mes rendez-vous : à venir et passés, toutes cliniques ; annulation
 * par le propriétaire (auditée à son nom, comme un geste de clinique) ;
 * demande de report, déposée dans la file des demandes de la clinique
 * (« la clinique vous recontacte »).
 */
class RendezVousProprietaireController extends Controller
{
    private const int PASSES_MAX = 30;

    public function index(ContexteProprietaire $contexte): JsonResponse
    {
        $base = fn () => RendezVous::withoutGlobalScopes()
            ->with(['clinique', 'lignePlanning', 'patient'])
            ->where(fn ($q) => $q->whereIn('client_id', $contexte->clientIds())->orWhereIn('patient_id', $contexte->patientIds()))
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
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous ne peut plus être annulé en ligne — contactez la clinique.']);
        }

        $mutation->appliquer(
            $rendezVous,
            ['status' => StatutRendezVous::Annule],
            $request->user(),
            entreesSupplementaires: ['motifAnnulation' => [null, 'Annulé par le propriétaire depuis l’espace client']],
        );

        return new RendezVousProprietaireResource($rendezVous->refresh()->load(['clinique', 'lignePlanning', 'patient']));
    }

    public function reporter(ContexteProprietaire $contexte, int $rendezVousId): JsonResponse
    {
        $rendezVous = $this->mien($contexte, $rendezVousId);

        if ($rendezVous->status !== StatutRendezVous::Prevu || ! $rendezVous->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous ne peut plus être reporté en ligne — contactez la clinique.']);
        }

        $demande = DemandeRendezVous::withoutGlobalScopes()->create([
            'clinique_id' => $rendezVous->clinique_id,
            'patient_id' => $rendezVous->patient_id,
            'client_id' => $rendezVous->client_id,
            'patient_name' => $rendezVous->patient_name,
            'species' => $rendezVous->species,
            'owner_name' => $rendezVous->owner_name,
            'reason' => 'Report — '.($rendezVous->reason ?: 'rendez-vous'),
            'souhait' => 'Report demandé par le propriétaire (rendez-vous du '
                .$rendezVous->starts_at->setTimezone($rendezVous->clinique->fuseau_horaire ?? 'Europe/Zurich')->format('d.m.Y à H:i').')',
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
            ->where(fn ($q) => $q->whereIn('client_id', $contexte->clientIds())->orWhereIn('patient_id', $contexte->patientIds()))
            ->findOrFail($rendezVousId);
    }
}
