<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le patient et tout son dossier médical (pesées, vaccinations,
 * visites, documents, relevés, carnet, puce, AMICUS) relèvent du
 * domaine patients. L'isolation d'organisation n'est pas l'affaire de
 * la policy — le scope global l'assure.
 */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, Patient $patient): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function update(User $user, Patient $patient): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
