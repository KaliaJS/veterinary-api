<?php

namespace App\Services\Alertes;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Enums\TypeAlerte;
use App\Models\Clinique;
use App\Models\Employe;

/**
 * Qui reçoit par e-mail une famille d'alertes d'une clinique (23.10.2026) :
 * les personnes actives de la clinique qui ont le droit du domaine et
 * n'ont pas coupé ces e-mails dans leurs réglages.
 */
class DestinatairesAlertes
{
    public function __construct(private CalculateurPermissions $permissions) {}

    /** @return list<string> */
    public function pour(Clinique $clinique, TypeAlerte $type, DomainePermission $domaine): array
    {
        return Employe::withoutGlobalScopes()
            ->whereHas('cliniques', fn ($requete) => $requete->whereKey($clinique->id))
            ->where('acces_suspendu', false)
            ->with(['user', 'role', 'derogations'])
            ->get()
            ->filter(fn (Employe $employe) => $employe->recoitAlertesEmail($type)
                && in_array($domaine->value, $this->permissions->pour($employe), true))
            ->map(fn (Employe $employe) => $employe->user?->email)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
