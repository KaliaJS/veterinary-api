<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\EmbaucherEmployeRequest;
use App\Http\Requests\V1\Equipe\ModifierEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use App\Models\Employe;
use App\Models\Role;
use App\Services\Equipe\Embauche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Fiches RH : une par personne dans l'organisation, avec les cliniques
 * où elle travaille. La LISTE est celle de la clinique du jeton (écran
 * Équipe) ; les GESTES (fiche, modification, embauche) sont bornés à
 * l'ORGANISATION — c'est l'écran Organisation qui les pilote, l'employé
 * visé peut ne pas travailler dans la clinique du jeton (résolution
 * sans binding implicite, comme le compte rendu SOAP). L'embauche lie le
 * compte Veterinary de la personne (son code employé) et respecte la
 * limite d'employés ACTIFS de la formule SaaS. Jamais de suppression
 * d'employé : le licenciement est un état.
 */
class EquipeEmployeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employe::class);

        $employes = Employe::query()
            ->with(['cliniques', 'lignePlanning', 'congesAVenir', 'user.specialites'])
            ->orderBy('id')
            ->paginate($this->parPage($request));

        return EmployeResource::collection($employes);
    }

    public function show(Request $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);
        $this->authorize('view', $fiche);

        return new EmployeResource($fiche->load(['cliniques', 'lignePlanning']));
    }

    public function store(EmbaucherEmployeRequest $request, Embauche $embauche): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $donnees = $request->validated();
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;
        $this->authorize('attribuerRole', [Employe::class, null, $embauche->role($organisation, $donnees)]);
        $compte = CompteEmployeController::compteDuCode($donnees['code']);

        $fiche = $embauche->embaucher($organisation, $compte, $donnees);

        return (new EmployeResource($fiche->load('cliniques')))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(ModifierEmployeRequest $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);
        $this->authorize('update', $fiche);

        // Changer de rôle : jamais le sien, jamais plus large que le sien
        if ($request->has('role_id') && $request->validated('role_id') !== $fiche->role_id) {
            $this->authorize('attribuerRole', [$fiche, Role::query()->find($request->validated('role_id'))]);
        }

        $fiche->update($request->validated());

        return new EmployeResource($fiche->load('cliniques'));
    }

    /**
     * Résolution SANS binding implicite (qui bornerait aux employés de la
     * clinique du jeton) : un employé de l'organisation, où qu'il
     * travaille.
     */
    public static function employeDeLOrganisation(int $id): Employe
    {
        return Employe::withoutGlobalScope('clinique')->findOrFail($id);
    }
}
