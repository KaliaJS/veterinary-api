<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Domaine stocks. `delete` couvre l'écart d'un lot périmé (suppression
 * tolérée : mouvement motivé « Péremption » puis effacement du lot).
 */
class LotPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function delete(User $user, Lot $lot): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }
}
