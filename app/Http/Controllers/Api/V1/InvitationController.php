<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\InvitationEmploye;
use App\Services\Equipe\Embauche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'invitation vue de la personne invitée, connectée avec n'importe quel
 * compte (avec ou sans clinique) : seule l'adresse invitée peut
 * accepter. Accepter crée sa fiche dans l'organisation ; le front
 * ouvre ensuite la session sur la première clinique.
 */
class InvitationController extends Controller
{
    public function afficher(Request $request, string $jeton): JsonResponse
    {
        $invitation = $this->invitation($jeton);
        $donnees = $invitation->donnees;
        $organisation = $invitation->organisation;

        $cliniques = ($donnees['toutes_les_cliniques'] ?? false)
            ? $organisation->cliniques()->orderBy('cliniques.id')->pluck('cliniques.nom')->all()
            : Clinique::withoutGlobalScopes()->whereIn('id', $donnees['cliniques'] ?? [])->orderBy('id')->pluck('nom')->all();

        return response()->json(['data' => [
            'organisation' => $organisation->nom,
            'cliniques' => $cliniques,
            'poste' => $donnees['poste'] ?? null,
            'invitee_par' => $invitation->invitant?->nom,
            'email' => $invitation->email,
            'statut' => match (true) {
                $invitation->acceptee_le !== null => 'acceptee',
                $invitation->annulee_le !== null => 'annulee',
                $invitation->expire_le->isPast() => 'expiree',
                default => 'en_attente',
            },
            'pour_ce_compte' => Str::lower($request->user()->email) === $invitation->email,
        ]]);
    }

    public function accepter(Request $request, string $jeton, Embauche $embauche): JsonResponse
    {
        $invitation = $this->invitation($jeton);
        $compte = $request->user();

        if (! $invitation->estEnAttente()) {
            throw ValidationException::withMessages(['invitation' => 'Cette invitation n’est plus valable. Demandez-en une nouvelle à la clinique.']);
        }
        if (Str::lower($compte->email) !== $invitation->email) {
            throw ValidationException::withMessages(['email' => "Cette invitation est pour {$invitation->email}. Connectez-vous avec cette adresse pour l’accepter."]);
        }
        // L'adresse doit être prouvée (code e-mail) : sinon quelqu'un qui
        // l'aurait prise d'avance recevrait l'accès à la clinique
        if ($compte->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => 'Confirmez d’abord votre adresse e-mail : connectez-vous avec le code reçu par e-mail, puis acceptez l’invitation.']);
        }

        $fiche = $embauche->embaucher($invitation->organisation, $compte, [...$invitation->donnees, 'nom' => $invitation->nom], 'email');
        $invitation->forceFill(['acceptee_le' => now(), 'employe_id' => $fiche->id])->save();

        return response()->json(['data' => [
            'organisation' => $invitation->organisation->nom,
            'clinique_id' => $fiche->cliniques()->value('cliniques.id'),
        ]], JsonResponse::HTTP_CREATED);
    }

    private function invitation(string $jeton): InvitationEmploye
    {
        $invitation = InvitationEmploye::duJeton($jeton);
        abort_if($invitation === null, 404, 'Invitation introuvable.');

        return $invitation->load('organisation', 'invitant');
    }
}
