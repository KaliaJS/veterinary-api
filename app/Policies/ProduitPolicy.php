<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le catalogue produits et ses mouvements relèvent du domaine stocks.
 * L'ajustement de stock est un `update` du produit ; l'isolation
 * d'organisation est l'affaire du scope global, pas de la policy.
 */
class ProduitPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function view(User $user, Produit $produit): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function update(User $user, Produit $produit): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }
}
