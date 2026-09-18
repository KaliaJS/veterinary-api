<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\StatutDemandeRattachement;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\EnregistrerAnimalRequest;
use App\Http\Requests\V1\Espace\ReclamerAnimalRequest;
use App\Http\Resources\V1\Espace\AnimalProprietaireResource;
use App\Models\Client;
use App\Models\DemandeRattachement;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Rules\NumeroPuce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mes animaux : ceux dont le compte est propriétaire et ceux des
 * fiches client rattachées (toutes organisations), la fiche détaillée
 * (carnet, visites), l'ajout d'un animal — qui appartient au compte,
 * sans clinique — et la réclamation par puce d'un animal déjà
 * enregistré par une clinique (validée par elle). Modèles de niveau
 * organisation : organisation_id explicite, pas de contexte clinique.
 */
class AnimalController extends Controller
{
    public function index(ContexteProprietaire $contexte): AnonymousResourceCollection
    {
        $animaux = $contexte->filtrerAnimaux(Patient::withoutGlobalScopes())
            ->with(['organisation', 'courbePoids'])
            ->orderBy('nom')
            ->get();

        return AnimalProprietaireResource::collection($animaux);
    }

    public function show(ContexteProprietaire $contexte, int $animalId): AnimalProprietaireResource
    {
        $animal = $contexte->filtrerAnimaux(Patient::withoutGlobalScopes())
            ->with(['organisation', 'courbePoids', 'vaccinations', 'visites.employe'])
            ->findOrFail($animalId);

        $animal->ordonnance_active_id = Ordonnance::withoutGlobalScopes()
            ->where('patient_id', $animal->id)
            ->where('statut', StatutOrdonnance::Active)
            ->orderByDesc('signee_le')
            ->value('id');

        return new AnimalProprietaireResource($animal);
    }

    public function store(EnregistrerAnimalRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validated();

        /** @var Client|null $fiche fiche client optionnelle (l'animal y est aussi posé) */
        $fiche = isset($donnees['fiche_id']) ? $contexte->clients()->firstWhere('id', (int) $donnees['fiche_id']) : null;
        $user = $contexte->user();

        $animal = DB::transaction(function () use ($donnees, $fiche, $user): Patient {
            $animal = Patient::withoutGlobalScopes()->create([
                'organisation_id' => $fiche?->organisation_id,
                'client_id' => $fiche?->id,
                'proprietaire_id' => $user->id,
                'nom' => $donnees['nom'],
                'espece' => $donnees['espece'],
                'race' => $donnees['race'] ?? null,
                'sexe' => $donnees['sexe'],
                'ne_le' => $donnees['ne_le'] ?? null,
                'puce' => isset($donnees['puce']) ? NumeroPuce::formater($donnees['puce']) : null,
                'client_depuis' => now()->year,
            ]);

            if (isset($donnees['poids_kg'])) {
                $animal->pesees()->create(['poids_kg' => $donnees['poids_kg'], 'pesee_le' => today()]);
            }

            return $animal;
        });

        return (new AnimalProprietaireResource($animal->load(['organisation', 'courbePoids'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * « Déjà connu d'une clinique ? » : l'animal est cherché par puce
     * parmi les fiches des cliniques ; une demande en attente est créée,
     * que la clinique accepte ou refuse. 422 par champ sinon.
     */
    public function reclamer(ReclamerAnimalRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $puce = NumeroPuce::formater($request->validated()['puce']);
        $user = $contexte->user();

        $animal = Patient::withoutGlobalScopes()->with('organisation')->where('puce', $puce)->first()
            ?? throw ValidationException::withMessages(['puce' => 'Aucun animal connu avec ce numéro de puce.']);

        if ($animal->proprietaire_id === $user->id) {
            throw ValidationException::withMessages(['puce' => 'Cet animal est déjà dans votre espace.']);
        }
        if ($animal->proprietaire_id !== null) {
            throw ValidationException::withMessages(['puce' => 'Cet animal est déjà rattaché à un autre compte.']);
        }
        if ($animal->organisation_id === null) {
            throw ValidationException::withMessages(['puce' => 'Aucune clinique ne suit cet animal.']);
        }

        $demande = DemandeRattachement::withoutGlobalScopes()
            ->where('patient_id', $animal->id)
            ->where('user_id', $user->id)
            ->where('statut', StatutDemandeRattachement::Attente)
            ->first();

        $creee = $demande === null;
        $demande ??= DemandeRattachement::withoutGlobalScopes()->create([
            'organisation_id' => $animal->organisation_id,
            'patient_id' => $animal->id,
            'user_id' => $user->id,
            'puce_saisie' => $puce,
            'statut' => StatutDemandeRattachement::Attente,
        ]);

        return response()->json(['data' => $this->presenterDemande($demande->setRelation('patient', $animal))], $creee ? 201 : 200);
    }

    /** Mes demandes de rattachement (en attente, acceptées, refusées), la plus récente d'abord. */
    public function rattachements(ContexteProprietaire $contexte): JsonResponse
    {
        $demandes = DemandeRattachement::withoutGlobalScopes()
            ->with(['patient.organisation'])
            ->where('user_id', $contexte->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $demandes->map(fn (DemandeRattachement $demande): array => $this->presenterDemande($demande))->all()]);
    }

    /** @return array<string, mixed> */
    private function presenterDemande(DemandeRattachement $demande): array
    {
        $animal = $demande->patient;

        return [
            'id' => $demande->id,
            'statut' => $demande->statut->value,
            'puce' => $demande->puce_saisie,
            'demandee_le' => $demande->created_at?->toIso8601String(),
            'traitee_le' => $demande->traitee_le?->toIso8601String(),
            'animal' => [
                'id' => $animal->id,
                'nom' => $animal->nom,
                'espece' => $animal->espece?->value,
                'espece_libelle' => $animal->espece?->libelle(),
                'race' => $animal->race,
            ],
            'organisation' => $animal->organisation ? ['id' => $animal->organisation->id, 'nom' => $animal->organisation->nom] : null,
        ];
    }
}
