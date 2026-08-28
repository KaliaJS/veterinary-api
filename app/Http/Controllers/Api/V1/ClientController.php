<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clients\EnregistrerClientRequest;
use App\Http\Requests\V1\Clients\ModifierClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Propriétaires — niveau ORGANISATION : la liste est la même depuis
 * toutes les cliniques du jeton (scope global organisation). Jamais de
 * suppression. Les agrégats de l'écran (solde dû = somme des dettes,
 * no-shows dérivés des RDV, client depuis = min des fiches) sont
 * calculés en requête, pas stockés.
 */
class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with('patientsParNom')
            ->tap(fn (Builder $requete) => $this->avecAgregats($requete))
            ->when($request->string('q')->isNotEmpty(), function (Builder $requete) use ($request): void {
                $q = '%'.$request->string('q')->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(prenom) like ?', [$q])
                    ->orWhereRaw('lower(nom) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(tel, \'\')) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$q])
                    ->orWhereRaw('lower(coalesce(localite, \'\')) like ?', [$q])
                    ->orWhereHas('patients', fn (Builder $patient) => $patient->whereRaw('lower(nom) like ?', [$q])));
            })
            ->when(
                $request->string('tri')->toString() === 'localite',
                fn (Builder $requete) => $requete->orderBy('localite')->orderBy('nom'),
            )
            ->when(
                $request->string('tri')->toString() === 'animaux',
                fn (Builder $requete) => $requete->orderByDesc('patients_count'),
            )
            ->when(
                ! in_array($request->string('tri')->toString(), ['localite', 'animaux'], true),
                fn (Builder $requete) => $requete->orderBy('nom')->orderBy('prenom'),
            )
            ->paginate($this->parPage($request));

        return ClientResource::collection($clients);
    }

    public function store(EnregistrerClientRequest $request): ClientResource
    {
        $this->authorize('create', Client::class);

        return new ClientResource(Client::create($request->validated()));
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('view', $client);

        return new ClientResource(
            $this->avecAgregats($client->newQuery())->with('patients')->findOrFail($client->id),
        );
    }

    public function update(ModifierClientRequest $request, Client $client): ClientResource
    {
        $this->authorize('update', $client);

        $client->update($request->validated());

        return new ClientResource($client);
    }

    /**
     * Le compteur de no-shows est celui du propriétaire, toutes
     * cliniques de l'organisation confondues — le scope clinique des
     * RDV est levé dans la sous-requête (le client borne déjà à
     * l'organisation).
     */
    private function avecAgregats(Builder $requete): Builder
    {
        return $requete
            ->withCount('patients')
            ->withSum('patients', 'dette')
            ->withMin('patients', 'client_depuis')
            ->withCount(['rendezVous as no_shows_count' => fn (Builder $rendezVous) => $rendezVous
                ->withoutGlobalScope('clinique')
                ->where('status', StatutRendezVous::Noshow)]);
    }
}
