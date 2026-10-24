<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\StatutDemandeRattachement;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\AccesAnimalRequest;
use App\Http\Requests\V1\Espace\EnregistrerAnimalRequest;
use App\Http\Requests\V1\Espace\ModifierAnimalRequest;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mes animaux : ceux dont le compte est propriétaire et ceux des
 * fiches client rattachées (toutes organisations), la fiche détaillée
 * (carnet, visites), l'ajout d'un animal — qui appartient au compte,
 * sans clinique — et la réclamation par puce d'un animal déjà
 * enregistré par une clinique (validée par elle). Un animal géré par le
 * compte (proprietaire_id) se modifie ici, et le compte décide du droit
 * de la clinique sur son identité (AccesClinique). Modèles de niveau
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
            ->with(['organisation', 'courbePoids', 'vaccinations.produit.schema', 'vaccinations.produit.valences', 'visites.employe'])
            ->findOrFail($animalId);

        $ordonnanceActiveId = Ordonnance::withoutGlobalScopes()
            ->where('patient_id', $animal->id)
            ->where('statut', StatutOrdonnance::Active)
            ->orderByDesc('signee_le')
            ->value('id');

        return (new AnimalProprietaireResource($animal))->avecOrdonnanceActive($ordonnanceActiveId);
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
                'client_depuis' => aujourdhui()->year,
            ]);

            if (isset($donnees['poids_kg'])) {
                $animal->pesees()->create(['poids_kg' => $donnees['poids_kg'], 'pesee_le' => aujourdhui()]);
            }

            return $animal;
        });

        return (new AnimalProprietaireResource($animal->load(['organisation', 'courbePoids'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Identité d'un animal géré par le compte (403 pour une fiche de clinique seulement). */
    public function update(ModifierAnimalRequest $request, ContexteProprietaire $contexte, int $animalId): AnimalProprietaireResource
    {
        $animal = $this->animalGere($contexte, $animalId);
        $donnees = $request->validated();
        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }
        $animal->update($donnees);

        return new AnimalProprietaireResource($animal->load(['organisation', 'courbePoids']));
    }

    /** Droit accordé à la clinique sur l'identité : « modification » ou « lecture ». */
    public function acces(AccesAnimalRequest $request, ContexteProprietaire $contexte, int $animalId): AnimalProprietaireResource
    {
        $animal = $this->animalGere($contexte, $animalId);
        $animal->update(['acces_clinique' => $request->validated('acces_clinique')]);

        return new AnimalProprietaireResource($animal->load(['organisation', 'courbePoids']));
    }

    /** L'animal, s'il est géré par le compte : 404 hors du compte, 403 si seule la clinique gère la fiche. */
    private function animalGere(ContexteProprietaire $contexte, int $animalId): Patient
    {
        $animal = $contexte->filtrerAnimaux(Patient::withoutGlobalScopes())->findOrFail($animalId);
        abort_unless(
            $animal->proprietaire_id === $contexte->user()->id,
            403,
            'Cette fiche est gérée par la clinique : réclamez l’animal par sa puce pour la gérer vous-même.',
        );

        return $animal;
    }

    /**
     * « Déjà connu d'une clinique ? » : l'animal est cherché par sa puce ET
     * son nom (23.10.2026 : le nom est le second facteur, une puce seule ne
     * révèle plus rien) parmi les fiches des cliniques ; une demande en
     * attente est créée, que la clinique accepte ou refuse. Une même
     * réponse pour une puce inconnue, un nom qui ne correspond pas ou un
     * animal déjà rattaché ailleurs.
     */
    public function reclamer(ReclamerAnimalRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $puce = NumeroPuce::formater($request->validated()['puce']);
        $nom = self::sansAccents($request->validated()['nom_proprietaire']);
        $user = $contexte->user();

        $animal = Patient::withoutGlobalScopes()->with(['organisation', 'client' => fn ($requete) => $requete->withoutGlobalScopes()])
            ->where('puce', $puce)
            ->whereNotNull('organisation_id')
            ->orderBy('id')
            ->get()
            ->first(fn (Patient $p): bool => $p->client !== null && self::sansAccents((string) $p->client->nom) === $nom);

        if ($animal?->proprietaire_id === $user->id) {
            throw ValidationException::withMessages(['puce' => 'Cet animal est déjà dans votre espace.']);
        }
        if ($animal === null || $animal->proprietaire_id !== null) {
            throw ValidationException::withMessages(['puce' => 'Aucun animal ne correspond à ce numéro de puce et à ce nom de famille. Vérifiez-les, ou appelez la clinique qui le suit.']);
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

    /** Nom comparé sans casse, accents ni espaces superflus (« Kiwi » = « kiwi »). */
    private static function sansAccents(string $texte): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', Str::ascii($texte))));
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
                'espece' => $animal->espece->value,
                'espece_libelle' => $animal->espece->libelle(),
                'race' => $animal->race,
            ],
            'organisation' => $animal->organisation ? ['id' => $animal->organisation->id, 'nom' => $animal->organisation->nom] : null,
        ];
    }
}
