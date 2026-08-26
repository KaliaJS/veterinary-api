<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\DemandeRendezVous;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DemandeRendezVousPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /** Retirer la demande de la file (confirmée ou refusée côté accueil). */
    public function delete(User $user, DemandeRendezVous $demandeRendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /** Placer la demande sur le planning (crée le RDV, retire l'entrée). */
    public function placer(User $user, DemandeRendezVous $demandeRendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
