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

    /** Retirer la demande sans répondre (doublon, demande farfelue). */
    public function delete(User $user, DemandeRendezVous $demandeRendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /** Répondre à la demande : l'accepter (crée le RDV), proposer d'autres horaires, refuser. */
    public function placer(User $user, DemandeRendezVous $demandeRendezVous): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
