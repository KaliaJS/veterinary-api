<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\CommandeFournisseur;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le droit fonctionnel seulement (domaine stocks) : `delete` ouvre la
 * suppression tolérée d'un bon de commande — le contrôleur refuse une
 * commande réceptionnée, dont le stock a déjà bougé.
 */
class CommandeFournisseurPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function update(User $user, CommandeFournisseur $commande): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function delete(User $user, CommandeFournisseur $commande): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }
}
