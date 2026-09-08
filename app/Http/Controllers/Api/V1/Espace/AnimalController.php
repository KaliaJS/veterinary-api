<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\EnregistrerAnimalRequest;
use App\Http\Resources\V1\Espace\AnimalProprietaireResource;
use App\Models\Client;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Rules\NumeroPuce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mes animaux : les patients de toutes les fiches client du compte
 * (toutes organisations), la fiche détaillée (carnet, visites), et
 * l'ajout d'un animal sur l'une des fiches — les modèles de niveau
 * organisation reçoivent organisation_id explicitement (pas de
 * contexte clinique ici).
 */
class AnimalController extends Controller
{
    public function index(ContexteProprietaire $contexte): AnonymousResourceCollection
    {
        $animaux = Patient::withoutGlobalScopes()
            ->with(['organisation', 'courbePoids'])
            ->whereIn('client_id', $contexte->clientIds())
            ->orderBy('nom')
            ->get();

        return AnimalProprietaireResource::collection($animaux);
    }

    public function show(ContexteProprietaire $contexte, int $animalId): AnimalProprietaireResource
    {
        $animal = Patient::withoutGlobalScopes()
            ->with(['organisation', 'courbePoids', 'vaccinations', 'visites.employe'])
            ->whereIn('client_id', $contexte->clientIds())
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

        $fiche = $contexte->clients()->firstWhere('id', (int) ($donnees['fiche_id'] ?? 0))
            ?? $contexte->clients()->first()
            ?? throw ValidationException::withMessages([
                'fiche_id' => 'Aucune fiche client : vos animaux apparaîtront après votre première visite.',
            ]);

        $animal = DB::transaction(function () use ($donnees, $fiche): Patient {
            /** @var Client $fiche */
            $animal = Patient::withoutGlobalScopes()->create([
                'organisation_id' => $fiche->organisation_id,
                'client_id' => $fiche->id,
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
}
