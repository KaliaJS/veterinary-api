<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\InviterEmployeRequest;
use App\Http\Resources\V1\InvitationEmployeResource;
use App\Mail\InvitationEquipe;
use App\Models\Employe;
use App\Models\InvitationEmploye;
use App\Services\Equipe\Embauche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    public function store(InviterEmployeRequest $request, Embauche $embauche): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $organisation = $this->contexte->clinique()->societe->organisation;
        $donnees = $request->validated();
        $this->authorize('attribuerRole', [Employe::class, null, $embauche->role($organisation, $donnees)]);
        $email = Str::lower($donnees['email']);

        $dejaLa = Employe::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereHas('user', fn ($compte) => $compte->where('email', $email))
            ->exists();
        if ($dejaLa) {
            throw ValidationException::withMessages([
                'email' => 'Cette personne fait déjà partie de l’équipe : ajoutez-lui des cliniques depuis sa fiche.',
            ]);
        }
        $embauche->verifierPlace($organisation);

        InvitationEmploye::query()->where('organisation_id', $organisation->id)->where('email', $email)->enAttente()->update(['annulee_le' => now()]);

        $invitation = new InvitationEmploye([
            'organisation_id' => $organisation->id,
            'email' => $email,
            'nom' => $donnees['nom'],
            'donnees' => collect($donnees)->except(['email'])->all(),
            'invitee_par' => $this->contexte->employe()?->id,
            'expire_le' => now()->addDays(InvitationEmploye::DUREE_JOURS),
        ]);
        $this->envoyer($invitation);

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
        $this->envoyer($fiche);

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

    private function envoyer(InvitationEmploye $invitation): void
    {
        $jeton = $invitation->nouveauJeton();
        Mail::to($invitation->email)->send(new InvitationEquipe($invitation->load('organisation', 'invitant'), $jeton));
    }

    private function invitationDeLOrganisation(int $id): InvitationEmploye
    {
        return InvitationEmploye::query()->where('organisation_id', $this->contexte->organisationId())->findOrFail($id);
    }
}
