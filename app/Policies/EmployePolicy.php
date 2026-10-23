<?php

namespace App\Policies;

use App\Auth\GardeEquipe;
use App\Enums\DomainePermission;
use App\Models\Employe;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Voir l'équipe relève du droit `equipe` ; les gestes RH (embauche,
 * modification, rôle, salaire, suspension, licenciement, affectations,
 * invitations) du droit `rh` (23.10.2026 : `equipe` était donné à tous
 * et suffisait pour se nommer administrateur). Garde-fous (GardeEquipe) :
 * jamais plus de droits que les siens, ni sur quelqu'un qui en a
 * davantage, ni sur soi-même pour le rôle et l'accès, et toujours un
 * administrateur actif. Jamais de suppression d'employé : le
 * licenciement est un état, la révocation d'accès une suspension.
 * L'annuaire (identité, ligne, poste, sans données RH) relève du
 * planning : la grille en a besoin pour ses zones d'absence.
 */
class EmployePolicy
{
    public function __construct(private GardeEquipe $garde) {}

    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    public function viewAnnuaire(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    public function view(User $user, Employe $employe): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    /** Gardes du week-end : entre collègues (voir l'équipe suffit) */
    public function organiserGardes(User $user): bool
    {
        return Gate::allows(DomainePermission::Equipe->value);
    }

    /** Salaire et motif de licenciement : gestion de l'équipe seulement */
    public function viewRh(User $user): bool
    {
        return Gate::allows(DomainePermission::Rh->value);
    }

    public function create(User $user): bool
    {
        return Gate::allows(DomainePermission::Rh->value);
    }

    public function update(User $user, Employe $employe): Response
    {
        if (! Gate::allows(DomainePermission::Rh->value)) {
            return Response::deny('La gestion de l’équipe n’est pas dans vos droits.');
        }

        // La fiche d'un administrateur ne se touche qu'entre administrateurs
        if (! Gate::allows(DomainePermission::Organisation->value)
            && in_array(DomainePermission::Organisation->value, $this->garde->droitsDe($employe), true)) {
            return Response::deny('Seul un administrateur peut modifier la fiche d’un administrateur.');
        }

        return Response::allow();
    }

    /** Donner un rôle : jamais à soi-même, jamais plus large que le sien. */
    public function attribuerRole(User $user, ?Employe $employe, ?Role $role): Response
    {
        $base = $employe ? $this->update($user, $employe) : ($this->create($user) ? Response::allow() : Response::deny());
        if ($base->denied()) {
            return $base;
        }

        if ($employe && $this->garde->estSoiMeme($employe)) {
            return Response::deny('Vous ne pouvez pas changer votre propre rôle.');
        }

        if (! $this->garde->couvreRole($role)) {
            return Response::deny('Ce rôle donne des droits que vous n’avez pas.');
        }

        if ($employe && $this->garde->estDernierAdministrateur($employe)
            && ! collect($role->domaines ?? [])->contains(DomainePermission::Organisation)) {
            return Response::deny('C’est le dernier administrateur actif de l’organisation : nommez-en un autre d’abord.');
        }

        return Response::allow();
    }

    /** Suspendre ou licencier : jamais soi-même, jamais le dernier administrateur. */
    public function retirerAcces(User $user, Employe $employe): Response
    {
        $base = $this->update($user, $employe);
        if ($base->denied()) {
            return $base;
        }

        if ($this->garde->estSoiMeme($employe)) {
            return Response::deny('Vous ne pouvez pas vous suspendre ni vous licencier vous-même.');
        }

        if (! $this->garde->couvreEmploye($employe)) {
            return Response::deny('Cette personne a des droits que vous n’avez pas : seul un administrateur peut la suspendre ou la licencier.');
        }

        if ($this->garde->estDernierAdministrateur($employe)) {
            return Response::deny('C’est le dernier administrateur actif de l’organisation : nommez-en un autre d’abord.');
        }

        return Response::allow();
    }
}
