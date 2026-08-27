<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Sejour;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * L'hospitalisation relève du domaine patients (soins au chevet —
 * l'ASV la tient autant que le vétérinaire). Les effets de stock et de
 * facturation des gestes (soin fait, sortie) sont des effets système :
 * ils n'exigent pas les domaines stocks/facturation. Pas de delete —
 * un séjour ne se supprime jamais, il se clôt par la sortie.
 */
class SejourPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, Sejour $sejour): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function update(User $user, Sejour $sejour): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
