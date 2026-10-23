<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutIntervention;
use App\Enums\TypeSalle;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Salles\EnregistrerSalleRequest;
use App\Http\Requests\V1\Salles\ModifierSalleRequest;
use App\Http\Resources\V1\SalleResource;
use App\Models\Intervention;
use App\Models\Salle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Premier endpoint métier — il porte les conventions de l'API :
 * ressources JSON, FormRequests, pagination, et surtout AUCUN filtre
 * clinique ici — le scope multi-clinique est global (middleware
 * `clinique` + trait AppartientAUneClinique).
 */
class SalleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Salle::class);

        return SalleResource::collection(
            Salle::query()->orderBy('nom')->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerSalleRequest $request): SalleResource
    {
        $this->authorize('create', Salle::class);

        return new SalleResource(Salle::create($request->validated()));
    }

    public function show(Salle $salle): SalleResource
    {
        $this->authorize('view', $salle);

        return new SalleResource($salle);
    }

    public function update(ModifierSalleRequest $request, Salle $salle): SalleResource
    {
        $this->authorize('update', $salle);

        $salle->update($request->validated());

        return new SalleResource($salle);
    }

    /**
     * Réservé aux salles de bloc (l'écran du bloc gère sa liste —
     * docs/data-model.md §2 et §11) : refusé tant que des interventions
     * non terminées y sont rattachées, qu'elle porte une ligne du
     * planning, ou qu'elle est la dernière salle de bloc de la
     * clinique. Les interventions terminées gardent son nom
     * (dénormalisé) ; les rendez-vous passés la délient (`nullOnDelete`).
     */
    public function destroy(Salle $salle): Response
    {
        $this->authorize('delete', $salle);

        if ($salle->type !== TypeSalle::Bloc) {
            throw ValidationException::withMessages(['salle' => 'Seules les salles de bloc se suppriment.']);
        }

        $occupee = Intervention::query()
            ->where('salle_id', $salle->id)
            ->whereNot('statut', StatutIntervention::Fini)
            ->count();

        if ($occupee > 0) {
            throw ValidationException::withMessages([
                'salle' => "Salle occupée par {$occupee} intervention".($occupee > 1 ? 's' : '').', réaffectez-les d\'abord.',
            ]);
        }

        if ($salle->lignePlanning()->exists()) {
            throw ValidationException::withMessages(['salle' => 'Cette salle porte une ligne du planning, retirez-la d\'abord.']);
        }

        $derniere = Salle::query()
            ->where('type', TypeSalle::Bloc)
            ->whereKeyNot($salle->id)
            ->doesntExist();

        if ($derniere) {
            throw ValidationException::withMessages(['salle' => 'Il faut au moins une salle de bloc.']);
        }

        $salle->delete();

        return response()->noContent();
    }
}
