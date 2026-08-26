<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le compte rendu SOAP est du dossier médical : domaine patients pour
 * la lecture comme la rédaction. L'isolation clinique passe par le
 * rendez-vous auquel il est adossé (jamais résolu directement).
 */
class CompteRenduPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
