<?php

namespace App\Policies;

use App\Enums\DomainePermission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Le patient et tout son dossier médical (pesées, vaccinations,
 * visites, documents, relevés, carnet, puce, AMICUS) relèvent du
 * domaine patients. L'isolation d'organisation n'est pas l'affaire de
 * la policy — le scope global l'assure. L'identité de l'animal (nom,
 * race, puce…) a son droit à part : le propriétaire qui gère la fiche
 * depuis son compte peut la réserver (AccesClinique::Lecture) ; les
 * actes médicaux de la clinique restent les siens.
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

    public function updateIdentity(User $user, Patient $patient): Response|bool
    {
        if (! $this->update($user, $patient)) {
            return false;
        }

        return $patient->cliniquePeutModifierIdentite()
            ? true
            : Response::deny('Fiche gérée par le propriétaire : l’identité de l’animal est en lecture seule.');
    }
}
