<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\LignePlanning;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class LignePlanningPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function view(User $user, LignePlanning $lignePlanning): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function update(User $user, LignePlanning $lignePlanning): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
