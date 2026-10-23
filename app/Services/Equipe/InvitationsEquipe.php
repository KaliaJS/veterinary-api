<?php

namespace App\Services\Equipe;

use App\Mail\InvitationEquipe;
use App\Models\Employe;
use App\Models\InvitationEmploye;
use App\Models\Organisation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inviter quelqu'un dans l'équipe : rien n'est créé avant que la
 * personne accepte avec l'adresse invitée. Depuis le 23.10.2026,
 * l'embauche par code employé passe aussi par là : la personne donne
 * son code, la clinique l'invite, elle accepte (jamais d'embauche sans
 * son accord).
 */
class InvitationsEquipe
{
    public function __construct(private Embauche $embauche) {}

    /**
     * @param  array<string, mixed>  $donnees  email, nom, et la fiche (poste, cliniques, contrat…)
     * @param  string  $champ  champ de l'erreur « déjà dans l'équipe »
     */
    public function inviter(Organisation $organisation, array $donnees, ?Employe $invitant, string $champ = 'email'): InvitationEmploye
    {
        $email = Str::lower($donnees['email']);

        $dejaLa = Employe::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereHas('user', fn ($compte) => $compte->where('email', $email))
            ->exists();
        if ($dejaLa) {
            throw ValidationException::withMessages([
                $champ => 'Cette personne fait déjà partie de l’équipe : ajoutez-lui des cliniques depuis sa fiche.',
            ]);
        }
        $this->embauche->verifierPlace($organisation);

        // Les cliniques se vérifient dès l'invitation, pas seulement à l'acceptation
        $siennes = $organisation->cliniques()->pluck('cliniques.id')->all();
        if (! ($donnees['toutes_les_cliniques'] ?? false)
            && array_intersect(array_map('intval', $donnees['cliniques'] ?? []), $siennes) === []) {
            throw ValidationException::withMessages(['cliniques' => 'Choisissez au moins une clinique.']);
        }

        InvitationEmploye::query()->where('organisation_id', $organisation->id)->where('email', $email)->enAttente()->update(['annulee_le' => now()]);

        $invitation = new InvitationEmploye([
            'organisation_id' => $organisation->id,
            'email' => $email,
            'nom' => $donnees['nom'],
            'donnees' => collect($donnees)->except(['email', 'code'])->all(),
            'invitee_par' => $invitant?->id,
            'expire_le' => now()->addDays(InvitationEmploye::DUREE_JOURS),
        ]);
        $this->envoyer($invitation);

        return $invitation;
    }

    /** Nouveau lien, nouveau délai : l'ancien lien cesse de marcher. */
    public function envoyer(InvitationEmploye $invitation): void
    {
        $jeton = $invitation->nouveauJeton();
        Mail::to($invitation->email)->send(new InvitationEquipe($invitation->load('organisation', 'invitant'), $jeton));
    }
}
