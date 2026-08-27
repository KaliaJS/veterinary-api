<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Devis;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Domaine facturation. `delete` n'ouvre que la suppression tolérée
 * d'un brouillon — le contrôleur garde le statut.
 */
class DevisPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function view(User $user, Devis $devis): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function update(User $user, Devis $devis): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function delete(User $user, Devis $devis): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }
}
