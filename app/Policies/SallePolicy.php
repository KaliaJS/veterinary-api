<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Salle;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Les salles relèvent du domaine planning. L'isolation multi-clinique
 * n'est PAS l'affaire des policies (le scope global l'assure) : ici,
 * seulement le droit fonctionnel.
 */
class SallePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function view(User $user, Salle $salle): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function update(User $user, Salle $salle): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
