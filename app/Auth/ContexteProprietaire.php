<?php

namespace App\Auth;

use App\Models\Client;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contexte de l'espace propriétaire : le compte connecté et ses fiches
 * client dans les cliniques — toutes organisations confondues, un
 * même compte pouvant être client de plusieurs organisations. Posé par
 * le middleware `proprietaire`, HORS de tout contexte clinique : les
 * scopes globaux sont inactifs, chaque requête de l'espace filtre
 * explicitement par ces fiches (clientIds / patientIds). Singleton
 * « scoped », réinitialisé à chaque requête.
 */
class ContexteProprietaire
{
    private ?User $user = null;

    /** @var Collection<int, Client>|null */
    private ?Collection $clients = null;

    /** @var list<int>|null */
    private ?array $patientIds = null;

    /** @param  Collection<int, Client>  $clients */
    public function definir(User $user, Collection $clients): void
    {
        $this->user = $user;
        $this->clients = $clients;
        $this->patientIds = null;
    }

    public function oublier(): void
    {
        $this->user = null;
        $this->clients = null;
        $this->patientIds = null;
    }

    public function estDefini(): bool
    {
        return $this->user !== null;
    }

    public function user(): User
    {
        return $this->user ?? throw new \LogicException('Contexte propriétaire non défini.');
    }

    /** @return Collection<int, Client> */
    public function clients(): Collection
    {
        return $this->clients ?? new Collection;
    }

    /** @return list<int> */
    public function clientIds(): array
    {
        return $this->clients()->pluck('id')->all();
    }

    /**
     * Patients de toutes les fiches — lus une fois par requête.
     *
     * @return list<int>
     */
    public function patientIds(): array
    {
        return $this->patientIds ??= $this->clientIds() === []
            ? []
            : Patient::withoutGlobalScopes()->whereIn('client_id', $this->clientIds())->pluck('id')->all();
    }
}
