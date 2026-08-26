<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Les propriétaires relèvent du domaine patients — le compte espace
 * client (créer/révoquer) est une mutation de la fiche (`update`).
 * Jamais de suppression de client : pas de `delete`.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, Client $client): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function update(User $user, Client $client): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
