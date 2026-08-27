<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\SessionTeleconsultation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * La téléconsultation relève du domaine `planning` : la session pilote
 * le statut d'un rendez-vous (cours, fini) — mêmes droits que les
 * gestes de statut du planning. Pas de delete : une session ne se
 * supprime jamais, elle se termine.
 */
class SessionTeleconsultationPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function view(User $user, SessionTeleconsultation $session): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function update(User $user, SessionTeleconsultation $session): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }
}
