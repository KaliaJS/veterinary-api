<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le droit fonctionnel seulement : l'isolation multi-clinique est
 * l'affaire du scope global. `delete` n'ouvre que la suppression
 * tolérée (fenêtre d'annulation d'une création — le contrôleur vérifie
 * la fenêtre) ; hors d'elle, un rendez-vous s'annule, jamais ne se
 * supprime.
 */
class RendezVousPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function view(User $user, RendezVous $rendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function update(User $user, RendezVous $rendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function delete(User $user, RendezVous $rendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
