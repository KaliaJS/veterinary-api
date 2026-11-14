<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

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
     * organisation : la suspendre, la licencier ou lui retirer le droit
     * `organisation` laisserait l'organisation sans personne pour la
     * gérer. Ne comptent que ceux qui restent : ni suspendus, ni
     * licenciés (même avant leur fin de contrat), contrat en cours
     * (24.10.2026).
     */
    public function estDernierAdministrateur(Employe $employe): bool
    {
        if (! in_array(DomainePermission::Organisation->value, $this->droitsDe($employe), true)) {
            return false;
        }

        return ! $this->administrateursActifs($employe->organisation_id)
            ->contains(fn (Employe $autre): bool => $autre->id !== $employe->id);
    }

    /**
     * L'administration emporte les droits du manager (14.11.2026) : une
     * exception qui refuse « gérer les absences » ou « voir les chiffres »
     * à quelqu'un qui a l'administration ne s'appliquerait pas, et l'écran
     * Rôles et droits la montrerait à tort. Effacée quand la personne
     * reçoit l'administration par son rôle (rôle attribué, rôle modifié).
     */
    public function oublierLesRefusComprisDansLAdministration(int $organisationId): void
    {
        $employes = Employe::withoutGlobalScopes()->where('organisation_id', $organisationId)->pluck('id');

        DerogationPermission::query()
            ->whereIn('employe_id', $employes)
            ->whereIn('domaine', [DomainePermission::GererAbsences, DomainePermission::VoirChiffres])
            ->where('est_accordee', false)
            ->with(['employe' => fn ($employe) => $employe->withoutGlobalScopes()->with('role', 'derogations')])
            ->get()
            ->filter(fn (DerogationPermission $refus): bool => in_array(DomainePermission::Organisation->value, $this->calculateur->pour($refus->employe), true))
            ->each(fn (DerogationPermission $refus) => $refus->delete());
    }

    /**
     * Les administrateurs qui restent : ni suspendus, ni licenciés (même
     * avant leur fin de contrat), contrat en cours. Une seule définition
     * pour les gestes RH et le réglage des rôles (24.10.2026).
     *
     * @return Collection<int, Employe>
     */
    public function administrateursActifs(int $organisationId): Collection
    {
        return Employe::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('acces_suspendu', false)
            ->whereNull('licenciement_notifie_le')
            ->where(fn ($q) => $q->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', aujourdhui()))
            ->with('role', 'derogations')
            ->get()
            ->filter(fn (Employe $employe): bool => in_array(DomainePermission::Organisation->value, $this->calculateur->pour($employe), true))
            ->values();
    }
}
