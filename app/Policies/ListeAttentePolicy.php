<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\ListeAttente;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ListeAttentePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function delete(User $user, ListeAttente $listeAttente): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /** Attribuer un créneau à l'inscrit (crée le RDV, retire l'entrée). */
    public function placer(User $user, ListeAttente $listeAttente): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
