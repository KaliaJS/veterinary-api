<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le site public engage l'image de l'organisation : réservé au domaine
 * `organisation` (l'Administrateur par défaut, dérogation possible).
 * Une seule ressource par organisation — abilities au niveau de la classe.
 */
class SiteWebPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Organisation->value);
    }

    public function update(User $user): bool
    {
        return Gate::allows(DomainePermission::Organisation->value);
    }
}
