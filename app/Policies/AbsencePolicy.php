<?php

namespace App\Policies;

use App\Enums\DomainePermission;
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

    public function view(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
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
