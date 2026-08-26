<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Une seule ressource par clinique (pas de binding par id) — les
 * abilities se vérifient au niveau de la classe.
 */
class HoraireOuverturePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function update(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
