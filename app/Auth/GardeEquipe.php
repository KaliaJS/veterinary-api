<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\Employe;
use App\Models\Role;

/**
 * Garde-fous des gestes RH (23.10.2026) : personne ne se donne plus de
 * droits qu'il n'en a, ne touche à quelqu'un qui en a davantage, ni ne
 * laisse l'organisation sans administrateur actif. Un administrateur
 * (droit `organisation`) n'a pas ces limites, sauf la dernière.
 */
class GardeEquipe
{
    public function __construct(
        private ContexteClinique $contexte,
        private CalculateurPermissions $calculateur,
    ) {}

    /** @return list<string> */
    public function droitsDe(Employe $employe): array
    {
        $employe->loadMissing('role', 'derogations');

        return $this->calculateur->pour($employe);
    }

    /** L'auteur de la requête a tous ces droits (ou est administrateur). */
    public function couvre(array $droits): bool
    {
        if ($this->contexte->permet(DomainePermission::Organisation)) {
            return true;
        }

        return array_diff($droits, $this->contexte->permissions()) === [];
    }

    /** L'auteur a tous les droits de cette personne. */
    public function couvreEmploye(Employe $employe): bool
    {
        return $this->couvre($this->droitsDe($employe));
    }

    /** L'auteur a tous les droits de ce rôle. */
    public function couvreRole(?Role $role): bool
    {
        return $this->couvre(collect($role->domaines ?? [])->map(fn (DomainePermission $d): string => $d->value)->all());
    }

    public function estSoiMeme(Employe $employe): bool
    {
        return $this->contexte->employe()?->id === $employe->id;
    }

    /**
     * Cette personne est le dernier administrateur actif de son
     * organisation (non suspendu, contrat en cours) : la suspendre, la
     * licencier ou lui retirer le droit `organisation` laisserait
     * l'organisation sans personne pour la gérer.
     */
    public function estDernierAdministrateur(Employe $employe): bool
    {
        if (! in_array(DomainePermission::Organisation->value, $this->droitsDe($employe), true)) {
            return false;
        }

        return ! Employe::withoutGlobalScopes()
            ->where('organisation_id', $employe->organisation_id)
            ->whereKeyNot($employe->id)
            ->where('acces_suspendu', false)
            ->where(fn ($q) => $q->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', today()))
            ->with('role', 'derogations')
            ->get()
            ->contains(fn (Employe $autre): bool => in_array(DomainePermission::Organisation->value, $this->calculateur->pour($autre), true));
    }
}
