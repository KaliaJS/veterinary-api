<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\CommandeBoutique;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * La boutique (commandes en ligne, comptoir, vitrine) relève du domaine
 * stocks — c'est le stock de la clinique qui bouge ; la vente comptoir
 * crée sa facture comme effet système, sans exiger le domaine
 * facturation (arbitrage : l'ASV tient la boutique).
 */
class CommandeBoutiquePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }

    public function update(User $user, CommandeBoutique $commande): bool
    {
        return Gate::allows(DomainePermission::Stocks->value);
    }
}
