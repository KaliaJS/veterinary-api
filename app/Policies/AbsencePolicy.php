<?php

namespace App\Policies;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Arbitrage de domaines : la lecture relève du planning (les absences
 * dessinent les colonnes bloquées de la grille), les mutations — cycle
 * demande / validation / refus, saisie directe, suppression — relèvent
 * de l'écran Équipe, donc du domaine `equipe`.
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /**
     * Une à une comme en liste (11.11.2026) : sans le droit Équipe, la
     * demande en attente ou refusée d'un autre ne se lit pas.
     */
    public function view(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Planning->value)
            && (Gate::allows(DomainePermission::Equipe->value)
                || $absence->statut === StatutAbsence::Validee
                || $absence->employe_id === app(ContexteClinique::class)->employe()?->id);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function update(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function delete(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function valider(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function refuser(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }
}
