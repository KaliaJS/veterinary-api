<?php

namespace App\Auth;

use App\Models\Clinique;

/**
 * Clinique du jeton de la requête courante. Renseigné par le
 * middleware `clinique`, lu par les global scopes des modèles
 * (Concerns\AppartientAUneClinique / AppartientAUneOrganisation).
 * Jamais défini en console (migrations, seeders) : les scopes y sont
 * donc inactifs. Enregistré en singleton « scoped » (réinitialisé à
 * chaque requête, sûr sous Octane).
 */
class ContexteClinique
{
    private ?Clinique $clinique = null;

    public function definir(Clinique $clinique): void
    {
        $this->clinique = $clinique;
    }

    public function oublier(): void
    {
        $this->clinique = null;
    }

    public function estDefini(): bool
    {
        return $this->clinique !== null;
    }

    public function clinique(): ?Clinique
    {
        return $this->clinique;
    }

    public function cliniqueId(): ?int
    {
        return $this->clinique?->id;
    }

    public function organisationId(): ?int
    {
        return $this->clinique?->societe->organisation_id;
    }
}
