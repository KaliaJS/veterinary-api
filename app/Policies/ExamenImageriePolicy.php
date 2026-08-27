<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\ExamenImagerie;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * L'imagerie relève du domaine `patients` (dossier médical — l'ASV
 * tient la worklist comme le laboratoire). Pas de delete : un examen
 * ne se supprime jamais, son compte rendu signé le fige.
 */
class ExamenImageriePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, ExamenImagerie $examen): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function update(User $user, ExamenImagerie $examen): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
