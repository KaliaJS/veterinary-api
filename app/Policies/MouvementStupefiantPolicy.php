<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Domaine stocks. Pas de `update` ni de `delete` : le registre est
 * immuable, une erreur se corrige par un mouvement inverse.
 */
class MouvementStupefiantPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }
}
