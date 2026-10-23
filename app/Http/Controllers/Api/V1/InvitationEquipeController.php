<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\InviterEmployeRequest;
use App\Http\Resources\V1\InvitationEmployeResource;
use App\Models\Employe;
use App\Models\InvitationEmploye;
use App\Services\Equipe\Embauche;
use App\Services\Equipe\InvitationsEquipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Invitations de l'équipe (domaine `equipe`) : l'administrateur invite
 * une personne par son e-mail ; elle se connecte avec cette adresse (son
 * compte naît alors s'il n'existe pas) et accepte. Une nouvelle
 * invitation à la même adresse remplace la précédente.
 */
class InvitationEquipeController extends Controller
{
    public function __construct(private ContexteClinique $contexte) {}

    public function index(): AnonymousResourceCollection
    {
        return InvitationEmployeResource::collection(
            InvitationEmploye::query()
                ->where('organisation_id', $this->contexte->organisationId())
                ->enAttente()
                ->with('invitant')
                ->latest('updated_at')
                ->get(),
        );
    }

    public function store(InviterEmployeRequest $request, Embauche $embauche, InvitationsEquipe $invitations): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $organisation = $this->contexte->clinique()->societe->organisation;
        $donnees = $request->validated();
        $this->authorize('attribuerRole', [Employe::class, null, $embauche->role($organisation, $donnees)]);

        $invitation = $invitations->inviter($organisation, $donnees, $this->contexte->employe());

        return (new InvitationEmployeResource($invitation->load('invitant')))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** Nouveau lien, nouveau délai : l'ancien lien cesse de marcher. */
    public function renvoyer(int $invitation): InvitationEmployeResource
    {
        $fiche = $this->invitationDeLOrganisation($invitation);
        $this->authorize('create', Employe::class);

        if ($fiche->acceptee_le !== null || $fiche->annulee_le !== null) {
            throw ValidationException::withMessages(['invitation' => 'Cette invitation n’est plus en attente.']);
        }
        app(InvitationsEquipe::class)->envoyer($fiche);

        return new InvitationEmployeResource($fiche->fresh('invitant'));
    }

    public function annuler(int $invitation): Response
    {
        $fiche = $this->invitationDeLOrganisation($invitation);
        $this->authorize('create', Employe::class);

        if ($fiche->acceptee_le === null) {
            $fiche->forceFill(['annulee_le' => now()])->save();
        }

        return response()->noContent();
    }

    private function invitationDeLOrganisation(int $id): InvitationEmploye
    {
        return InvitationEmploye::query()->where('organisation_id', $this->contexte->organisationId())->findOrFail($id);
    }
}
