<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\Employe;

/**
 * Contexte de la requête courante : clinique du jeton, employé
 * authentifié et ses permissions effectives — chargées en base par le
 * middleware `clinique` et conservées ici pour la durée de la requête
 * (cache de requête : les Gates ne retournent jamais en base).
 * Jamais défini en console (migrations, seeders) : les scopes y sont
 * donc inactifs. Enregistré en singleton « scoped » (réinitialisé à
 * chaque requête, sûr sous Octane).
 */
class ContexteClinique
{
    private ?Clinique $clinique = null;

    private ?Employe $employe = null;

    /** @var list<string> */
    private array $permissions = [];

    /** @param list<string> $permissions */
    public function definir(Clinique $clinique, ?Employe $employe = null, array $permissions = []): void
    {
        $this->clinique = $clinique;
        $this->employe = $employe;
        $this->permissions = $permissions;
    }

    public function oublier(): void
    {
        $this->clinique = null;
        $this->employe = null;
        $this->permissions = [];
    }

    /**
     * L'état présent, pour le rétablir après un sous-contexte.
     *
     * @return array{0: ?Clinique, 1: ?Employe, 2: list<string>}
     */
    public function etat(): array
    {
        return [$this->clinique, $this->employe, $this->permissions];
    }

    /** @param  array{0: ?Clinique, 1: ?Employe, 2: list<string>}  $etat */
    public function retablir(array $etat): void
    {
        [$this->clinique, $this->employe, $this->permissions] = $etat;
    }

    public function estDefini(): bool
    {
        return $this->clinique !== null;
    }

    public function clinique(): ?Clinique
    {
        return $this->clinique;
    }

    public function employe(): ?Employe
    {
        return $this->employe;
    }

    public function cliniqueId(): ?int
    {
        return $this->clinique?->id;
    }

    public function organisationId(): ?int
    {
        return $this->clinique?->societe->organisation_id;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function permet(DomainePermission|string $domaine): bool
    {
        $valeur = $domaine instanceof DomainePermission ? $domaine->value : $domaine;

        return in_array($valeur, $this->permissions, true);
    }
}
