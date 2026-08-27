<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Intervention;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le bloc opératoire a son propre domaine (`bloc`) — le rôle Assistant
 * par défaut ne l'a pas. Pas de delete : une intervention ne se
 * supprime jamais, son cycle se clôt en `fini`.
 */
class InterventionPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Bloc->value);
    }

    public function view(User $user, Intervention $intervention): bool
    {
        return Gate::allows(DomainePermission::Bloc->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Bloc->value);
    }

    public function update(User $user, Intervention $intervention): bool
    {
        return Gate::allows(DomainePermission::Bloc->value);
    }
}
