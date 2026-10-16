<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Espece;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Patients\EnregistrerPatientRequest;
use App\Http\Requests\V1\Patients\ModifierPatientRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Patient;
use App\Rules\NumeroPuce;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Patients — niveau ORGANISATION comme les clients (scope global
 * organisation, partagés entre les cliniques du jeton). Jamais de
 * suppression de fiche. Filtres et tris de l'écran Patients :
 * `filtre` tous|chien|chat|nac|alertes|rappels, `tri` nom|rappels|visite,
 * `q` sur nom, race, puce et propriétaire ; `meta.facettes` porte les
 * compteurs de chaque filtre sur la recherche courante.
 */
class PatientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->with(['client', 'courbePoids', 'rendezVousAVenir'])
            ->withMin('vaccinations', 'echeance_le')
            ->withMax('visites', 'visite_le')
            ->tap(fn (Builder $requete) => $this->rechercher($requete, $request))
            ->when($request->string('filtre')->toString(), fn (Builder $requete, string $filtre) => match ($filtre) {
                'chien' => $requete->where('espece', Espece::Chien),
                'chat' => $requete->where('espece', Espece::Chat),
                'nac' => $requete->whereNotIn('espece', [Espece::Chien, Espece::Chat]),
                'alertes' => $requete->where(fn (Builder $clause) => $clause
                    ->where('est_agressif', true)
                    ->orWhere('a_allergies', true)
                    ->orWhere('dette', '>', 0)),
                'rappels' => $requete->whereHas(
                    'vaccinations',
                    fn (Builder $vaccination) => $vaccination->where('echeance_le', '<=', $this->horizonRappels()),
                ),
                default => $requete,
            })
            ->when($request->string('tri')->toString(), fn (Builder $requete, string $tri) => match ($tri) {
                'rappels' => $requete
                    ->orderByRaw('vaccinations_min_echeance_le is null')
                    ->orderBy('vaccinations_min_echeance_le'),
                'visite' => $requete->orderByDesc('visites_max_visite_le'),
                default => $requete->orderBy('nom'),
            }, fn (Builder $requete) => $requete->orderBy('nom'))
            ->paginate($this->parPage($request));

        return PatientResource::collection($patients)->additional([
            'meta' => ['facettes' => $this->facettes($request)],
        ]);
    }

    /** Recherche `q` sur nom, race, puce et propriétaire. */
    private function rechercher(Builder $requete, Request $request): void
    {
        if ($request->string('q')->isEmpty()) {
            return;
        }

        $q = '%'.$request->string('q')->lower().'%';

        $requete->where(fn (Builder $clause) => $clause
            ->whereRaw('lower(patients.nom) like ?', [$q])
            ->orWhereRaw('lower(coalesce(patients.race, \'\')) like ?', [$q])
            ->orWhereRaw('lower(coalesce(patients.puce, \'\')) like ?', [$q])
            ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('lower(nom) like ?', [$q])));
    }

    /**
     * Compteurs des filtres de l'écran, sur la recherche courante mais
     * indépendants du filtre actif (chaque onglet affiche le sien) —
     * une seule requête d'agrégats conditionnels.
     *
     * @return array{tous: int, chien: int, chat: int, nac: int, alertes: int, rappels: int}
     */
    private function facettes(Request $request): array
    {
        $compte = fn (string $condition): string => "sum(case when {$condition} then 1 else 0 end)";

        $ligne = Patient::query()
            ->tap(fn (Builder $requete) => $this->rechercher($requete, $request))
            ->selectRaw(implode(', ', [
                'count(*) as tous',
                $compte('patients.espece = ?').' as chien',
                $compte('patients.espece = ?').' as chat',
                $compte('patients.espece not in (?, ?)').' as nac',
                $compte('patients.est_agressif = 1 or patients.a_allergies = 1 or patients.dette > 0').' as alertes',
                $compte('exists (select 1 from vaccinations where vaccinations.patient_id = patients.id and vaccinations.echeance_le <= ?)').' as rappels',
            ]), [
                Espece::Chien->value,
                Espece::Chat->value,
                Espece::Chien->value,
                Espece::Chat->value,
                $this->horizonRappels()->toDateString(),
            ])
            ->toBase()
            ->first();

        return [
            'tous' => (int) ($ligne->tous ?? 0),
            'chien' => (int) ($ligne->chien ?? 0),
            'chat' => (int) ($ligne->chat ?? 0),
            'nac' => (int) ($ligne->nac ?? 0),
            'alertes' => (int) ($ligne->alertes ?? 0),
            'rappels' => (int) ($ligne->rappels ?? 0),
        ];
    }

    /** Un rappel vaccinal « proche » : échéance dans les 30 jours (ou passée). */
    private function horizonRappels(): Carbon
    {
        return today()->addDays(30);
    }

    public function store(EnregistrerPatientRequest $request): PatientResource
    {
        $this->authorize('create', Patient::class);

        $donnees = $request->validated();

        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }

        $patient = Patient::create([
            ...$donnees,
            'client_depuis' => $donnees['client_depuis'] ?? now()->year,
        ]);

        return new PatientResource($patient->load('client'));
    }

    public function show(Patient $patient): PatientResource
    {
        $this->authorize('view', $patient);

        // Les signataires (vaccins, visites) peuvent être d'une autre
        // clinique de l'organisation : leur scope clinique est levé
        // pour afficher le nom — lecture seule.
        $patient->load([
            'client',
            'pesees' => fn ($requete) => $requete->orderBy('pesee_le')->orderBy('id'),
            'vaccinations' => fn ($requete) => $requete->orderBy('echeance_le'),
            'vaccinations.employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'visites' => fn ($requete) => $requete->orderByDesc('visite_le')->orderByDesc('id'),
            'visites.employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'documents' => fn ($requete) => $requete->orderByDesc('etabli_le')->orderByDesc('id'),
            'relevesOphtalmologiques' => fn ($requete) => $requete->orderByDesc('releve_le')->orderByDesc('id'),
            'relevesDentaires',
            'courbePoids',
            'rendezVousAVenir',
        ])->loadMin('vaccinations', 'echeance_le')->loadMax('visites', 'visite_le');

        return new PatientResource($patient);
    }

    public function update(ModifierPatientRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        // L identité peut être réservée par le propriétaire (droits sur l animal) ;
        // alertes, traitement, rappels restent à la clinique
        if (array_intersect_key($donnees, array_flip(Patient::CHAMPS_IDENTITE)) !== []) {
            $this->authorize('updateIdentity', $patient);
        }

        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }

        $patient->update($donnees);

        return new PatientResource($patient->load('client'));
    }
}
