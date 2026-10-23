<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Ordonnance;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Le registre des ordonnances est du dossier médical : domaine
 * patients (une ASV le voit — la facturation, elle, lui est fermée).
 * `delete` n'ouvre que la suppression tolérée (fenêtre d'annulation
 * d'une signature — le contrôleur vérifie la fenêtre et restaure le
 * stock).
 */
class OrdonnancePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function view(User $user, Ordonnance $ordonnance): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    /** Une ordonnance naît signée : signer (ou renouveler) exige le droit `prescrire`. */
    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Patients->value) && Gate::allows(DomainePermission::Prescrire->value);
    }

    public function update(User $user, Ordonnance $ordonnance): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }

    public function delete(User $user, Ordonnance $ordonnance): bool
    {
        return Gate::allows(DomainePermission::Patients->value);
    }
}
