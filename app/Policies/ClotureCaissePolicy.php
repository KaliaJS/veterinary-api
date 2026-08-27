<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Domaine facturation. Une clôture ne se modifie ni ne se supprime —
 * seuls la lecture et l'arrêté existent.
 */
class ClotureCaissePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }
}
