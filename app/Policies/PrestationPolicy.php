<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Catalogue d'actes — domaine facturation, lecture seule pour
 * l'instant (la gestion du catalogue viendra avec les réglages).
 */
class PrestationPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }
}
