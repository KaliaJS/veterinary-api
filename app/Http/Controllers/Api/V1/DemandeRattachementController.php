<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutDemandeRattachement;
use App\Http\Controllers\Controller;
use App\Models\DemandeRattachement;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Côté clinique : demandes de rattachement en attente (un client
 * réclame un animal de l'organisation par sa puce). Accepter pose
 * proprietaire_id sur le patient — la fiche reste celle de la
 * clinique, désormais aussi visible par son propriétaire ; refuser
 * clôt la demande.
 */
class DemandeRattachementController extends Controller
{
    public function index(): JsonResponse
    {
        // Le dossier médical : droit `patients` (23.10.2026)
        $this->authorize('viewAny', Patient::class);

        $demandes = DemandeRattachement::query()
            ->with(['patient.client', 'user'])
            ->where('statut', StatutDemandeRattachement::Attente)
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $demandes->map(fn (DemandeRattachement $demande): array => $this->presenter($demande))->all()]);
    }

    public function accepter(DemandeRattachement $demande, ContexteClinique $contexte): JsonResponse
    {
        // Donner l'animal à un compte : modifier la fiche
        $this->authorize('update', $demande->patient);
        $this->enAttente($demande);

        DB::transaction(function () use ($demande, $contexte): void {
            $patient = $demande->patient;

            if ($patient->proprietaire_id !== null && $patient->proprietaire_id !== $demande->user_id) {
                throw ValidationException::withMessages(['demande' => 'Cet animal est déjà rattaché à un autre compte.']);
            }

            $patient->forceFill(['proprietaire_id' => $demande->user_id])->save();
            $demande->forceFill([
                'statut' => StatutDemandeRattachement::Acceptee,
                'traitee_le' => now(),
                'traitee_par' => $contexte->employe()?->id,
            ])->save();
        });

        return response()->json(['data' => $this->presenter($demande->load(['patient.client', 'user']))]);
    }

    public function refuser(DemandeRattachement $demande, ContexteClinique $contexte): JsonResponse
    {
        $this->authorize('update', $demande->patient);
        $this->enAttente($demande);

        $demande->forceFill([
            'statut' => StatutDemandeRattachement::Refusee,
            'traitee_le' => now(),
            'traitee_par' => $contexte->employe()?->id,
        ])->save();

        return response()->json(['data' => $this->presenter($demande->load(['patient.client', 'user']))]);
    }

    private function enAttente(DemandeRattachement $demande): void
    {
        if ($demande->statut !== StatutDemandeRattachement::Attente) {
            throw ValidationException::withMessages(['demande' => 'Cette demande a déjà été traitée.']);
        }
    }

    /** @return array<string, mixed> */
    private function presenter(DemandeRattachement $demande): array
    {
        return [
            'id' => $demande->id,
            'statut' => $demande->statut->value,
            'puce_saisie' => $demande->puce_saisie,
            'demandee_le' => $demande->created_at?->toIso8601String(),
            'traitee_le' => $demande->traitee_le?->toIso8601String(),
            'patient' => [
                'id' => $demande->patient->id,
                'nom' => $demande->patient->nom,
                'espece' => $demande->patient->espece->value,
                'race' => $demande->patient->race,
                'puce' => $demande->patient->puce,
                'proprietaire_fiche' => $demande->patient->client?->nom_affiche,
            ],
            'demandeur' => [
                'nom' => $demande->user->name,
                'email' => $demande->user->email,
            ],
        ];
    }
}
