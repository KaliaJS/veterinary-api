<?php

namespace App\Auth;

use App\Enums\VerificationOrganisation;
use App\Models\Client;
use App\Models\InvitationAcces;
use App\Models\InvitationEmploye;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accès sur invitation (décision de Jim, 27.10.2026) : qui peut créer un
 * compte tant que `plateforme.acces_sur_invitation` est vrai. Une adresse
 * de la liste de la plateforme, une adresse invitée dans l'équipe d'une
 * clinique admise (vérifiée par la plateforme) ou une adresse sur une
 * fiche client d'une clinique admise. Les comptes qui existent déjà ne
 * passent jamais par ici.
 */
class AdressesInvitees
{
    public function estFerme(): bool
    {
        return (bool) config('plateforme.acces_sur_invitation');
    }

    public function peutCreerUnCompte(string $email): bool
    {
        if (! $this->estFerme()) {
            return true;
        }

        $email = mb_strtolower(trim($email));
        $admise = fn (Builder $organisation) => $organisation->where('verification', VerificationOrganisation::Verifiee);

        return InvitationAcces::query()->where('email', $email)->exists()
            || InvitationEmploye::query()->enAttente()->whereRaw('lower(email) = ?', [$email])->whereHas('organisation', $admise)->exists()
            // Hors de toute clinique : la fiche peut être dans n'importe quelle organisation
            || Client::withoutGlobalScope('organisation')->whereRaw('lower(email) = ?', [$email])->whereHas('organisation', $admise)->exists();
    }

    /** Le compte est créé : l'invitation de la liste (s'il y en a une) est utilisée. */
    public function marquerUtilisee(string $email): void
    {
        InvitationAcces::query()->where('email', mb_strtolower(trim($email)))->whereNull('utilisee_le')->update(['utilisee_le' => now()]);
    }
}
