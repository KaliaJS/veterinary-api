<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Auth\GardeEquipe;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\EmbaucherEmployeRequest;
use App\Http\Requests\V1\Equipe\ModifierEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use App\Http\Resources\V1\InvitationEmployeResource;
use App\Models\Employe;
use App\Models\Role;
use App\Services\Equipe\Embauche;
use App\Services\Equipe\InvitationsEquipe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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

        // Jamais d'embauche sans l'accord de la personne (23.10.2026) : son
        // code sert à la trouver, elle reçoit une invitation et l'accepte
        $invitation = app(InvitationsEquipe::class)->inviter(
            $organisation,
            [...$donnees, 'email' => $compte->email, 'nom' => $donnees['nom'] ?? $compte->name],
            app(ContexteClinique::class)->employe(),
            'code',
        );

        return (new InvitationEmployeResource($invitation->load('invitant')))->response()->setStatusCode(JsonResponse::HTTP_ACCEPTED);
    }

    public function update(ModifierEmployeRequest $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);
        $this->authorize('update', $fiche);

        // Son propre contrat (salaire, taux, congés…) : réglé par quelqu'un
        // d'autre, sauf par un administrateur, qui tient l'organisation (24.10.2026)
        $contrat = ['taux', 'contrat', 'contrat_statut', 'salaire_mensuel', 'conges_droit', 'conges_pris'];
        if (app(GardeEquipe::class)->estSoiMeme($fiche) && ! Gate::allows(DomainePermission::Organisation->value)
            && array_intersect($contrat, array_keys($request->validated())) !== []) {
            throw new AuthorizationException('Votre contrat se règle par une autre personne qui a le droit RH.');
        }

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
