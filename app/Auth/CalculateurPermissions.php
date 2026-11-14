<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\Employe;

/**
 * Permissions effectives d'un employé : les domaines de son rôle,
 * ajustés par ses dérogations. Toujours recalculées depuis la base
 * (le résultat n'est conservé que le temps de la requête, dans le
 * ContexteClinique) : une modification prend effet immédiatement.
 * L'administration emporte les droits du manager (14.11.2026) : qui a
 * `organisation`, par son rôle ou à titre personnel, gère les absences et
 * voit les chiffres.
 */
class CalculateurPermissions
{
    /** @return list<string> */
    public function pour(Employe $employe): array
    {
        $permissions = collect($employe->role->domaines ?? [])
            ->map(fn (DomainePermission $domaine): string => $domaine->value);

        foreach ($employe->derogations as $derogation) {
            $permissions = $derogation->est_accordee
                ? $permissions->push($derogation->domaine->value)
                : $permissions->reject(fn (string $valeur): bool => $valeur === $derogation->domaine->value);
        }

        if ($permissions->contains(DomainePermission::Organisation->value)) {
            $permissions = $permissions->merge([DomainePermission::GererAbsences->value, DomainePermission::VoirChiffres->value]);
        }

        return $permissions->unique()->values()->all();
    }
}
