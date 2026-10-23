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

    /** Inscrire au registre : droit `stupefiants` (le vétérinaire par défaut) */
    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value) && Gate::allows(DomainePermission::Stupefiants->value);
    }
}
