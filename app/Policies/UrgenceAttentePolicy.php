<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\UrgenceAttente;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UrgenceAttentePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function delete(User $user, UrgenceAttente $urgenceAttente): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /** Caser l'urgence sur le planning (crée le RDV, retire l'entrée). */
    public function placer(User $user, UrgenceAttente $urgenceAttente): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
