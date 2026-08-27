<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Les gestes RH (embauche, modification, licenciement, suspension,
 * gardes) relèvent du domaine équipe. Jamais de suppression d'employé :
 * le licenciement est un état, la révocation d'accès une suspension.
 */
class EmployePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function view(User $user, Employe $employe): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function update(User $user, Employe $employe): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }
}
