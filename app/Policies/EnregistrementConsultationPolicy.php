<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * L'enregistrement d'une consultation sert au compte rendu : domaine
 * patients, comme le compte rendu lui-même. L'isolation d'organisation
 * passe par le scope du modèle et par le rendez-vous porteur.
 */
class EnregistrementConsultationPolicy
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
