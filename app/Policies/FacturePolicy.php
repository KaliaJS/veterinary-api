<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Facture;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Domaine facturation — celui qu'une ASV ne voit pas (contrainte
 * produit, cf. RolesParDefaut). `delete` n'ouvre que la suppression
 * tolérée : la fenêtre d'annulation d'une création, contrôlée au
 * contrôleur (le journal, lui, ne supprime jamais).
 */
class FacturePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function view(User $user, Facture $facture): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function update(User $user, Facture $facture): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }

    public function delete(User $user, Facture $facture): bool
    {
        return Gate::allows(DomainePermission::Facturation->value);
    }
}
