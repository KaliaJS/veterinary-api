<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Analyse;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le laboratoire relève du domaine `patients` (dossier médical —
 * l'ASV tient la paillasse comme l'hospitalisation). Pas de delete :
 * une demande d'analyses ne se supprime jamais, son cycle se clôt en
 * `ok` (validé signé).
 */
class AnalysePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, Analyse $analyse): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function update(User $user, Analyse $analyse): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
