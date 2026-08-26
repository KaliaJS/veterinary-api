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

/**
 * Patients — niveau ORGANISATION comme les clients (scope global
 * organisation, partagés entre les cliniques du jeton). Jamais de
 * suppression de fiche. Filtres et tris de l'écran Patients :
 * `filtre` tous|chien|chat|nac|alertes|rappels, `tri` nom|rappels|visite,
 * `q` sur nom, race, puce et propriétaire.
 */
class PatientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->with('client')
            ->withMin('vaccinations', 'echeance_le')
            ->withMax('visites', 'visite_le')
            ->when($request->string('q')->isNotEmpty(), function (Builder $requete) use ($request): void {
                $q = '%'.$request->string('q')->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(nom) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(race, \'\')) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(puce, \'\')) like ?', [$q])
                    ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('lower(nom) like ?', [$q])));
            })
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
                    fn (Builder $vaccination) => $vaccination->where('echeance_le', '<=', today()->addDays(30)),
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

        return PatientResource::collection($patients);
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
        ])->loadMin('vaccinations', 'echeance_le')->loadMax('visites', 'visite_le');

        return new PatientResource($patient);
    }

    public function update(ModifierPatientRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        if (isset($donnees['puce'])) {
            $donnees['puce'] = NumeroPuce::formater($donnees['puce']);
        }

        $patient->update($donnees);

        return new PatientResource($patient->load('client'));
    }
}
