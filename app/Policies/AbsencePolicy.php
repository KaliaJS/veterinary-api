<?php

namespace App\Policies;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Arbitrage de domaines : la lecture relève du planning (les absences
 * dessinent les colonnes bloquées de la grille) ; les gestes — valider,
 * refuser, poser pour un autre, modifier, supprimer — du droit « Gérer
 * les absences » (audit C04, Jim, 14.11.2026 : « Voir l'équipe », donné à
 * tous, y suffisait). Personne ne décide de sa propre absence, sauf un
 * administrateur : sa demande en attente se corrige ou se retire, rien de
 * plus. Ses congés à soi se demandent par /equipe/demandes-conge.
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(DomainePermission::Planning->value);
    }

    /**
     * Une à une comme en liste (11.11.2026) : sans le droit « Gérer les
     * absences » (audit C29), la demande en attente ou refusée d'un autre
     * ne se lit pas.
     */
    public function view(User $user, Absence $absence): bool
    {
        return Gate::allows(DomainePermission::Planning->value)
            && (Gate::allows(DomainePermission::GererAbsences->value)
                || $absence->statut === StatutAbsence::Validee
                || $absence->employe_id === app(ContexteClinique::class)->employe()?->id);
    }

    /** Poser une absence : validée d'emblée pour soi, seulement par un administrateur. */
    public function create(User $user, ?int $employeId = null, ?StatutAbsence $statut = null): Response
    {
        if (! Gate::allows(DomainePermission::GererAbsences->value)) {
            return Response::deny('La gestion des absences n’est pas dans vos droits.');
        }

        if ($statut === StatutAbsence::Validee && $this->estLaSienne($employeId) && ! $this->estAdministrateur()) {
            return Response::deny('Votre propre absence se valide par une autre personne : envoyez-la en demande.');
        }

        return Response::allow();
    }

    public function update(User $user, Absence $absence): Response
    {
        return $this->gerer($absence, saDemandeEnAttente: true);
    }

    public function delete(User $user, Absence $absence): Response
    {
        return $this->gerer($absence, saDemandeEnAttente: true);
    }

    public function valider(User $user, Absence $absence): Response
    {
        return $this->gerer($absence);
    }

    public function refuser(User $user, Absence $absence): Response
    {
        return $this->gerer($absence);
    }

    /**
     * Le droit, et jamais sur sa propre absence, sauf un administrateur ;
     * `saDemandeEnAttente` : sa demande pas encore décidée se corrige ou se
     * retire (elle reste à valider par un autre).
     */
    private function gerer(Absence $absence, bool $saDemandeEnAttente = false): Response
    {
        if (! Gate::allows(DomainePermission::GererAbsences->value)) {
            return Response::deny('La gestion des absences n’est pas dans vos droits.');
        }

        if ($this->estLaSienne($absence->employe_id) && ! $this->estAdministrateur()
            && ! ($saDemandeEnAttente && $absence->statut === StatutAbsence::EnAttente)) {
            return Response::deny('Votre propre absence se décide par une autre personne.');
        }

        return Response::allow();
    }

    private function estLaSienne(?int $employeId): bool
    {
        $moi = app(ContexteClinique::class)->employe()?->id;

        return $moi !== null && $employeId === $moi;
    }

    private function estAdministrateur(): bool
    {
        return Gate::allows(DomainePermission::Organisation->value);
    }
}
