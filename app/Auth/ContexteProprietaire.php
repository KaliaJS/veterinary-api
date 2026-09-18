<?php

namespace App\Auth;

use App\Models\Client;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
     * Animaux du compte : ceux dont il est le propriétaire
     * (proprietaire_id) et ceux des fiches client rattachées.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $requete  requête sur `patients`
     * @return Builder<TModel>
     */
    public function filtrerAnimaux(Builder $requete): Builder
    {
        return $requete->where(function (Builder $q): void {
            $q->where('proprietaire_id', $this->user()->id);
            if ($this->clientIds() !== []) {
                $q->orWhereIn('client_id', $this->clientIds());
            }
        });
    }

    /**
     * Patients du compte (propriétaire ou fiches) — lus une fois par requête.
     *
     * @return list<int>
     */
    public function patientIds(): array
    {
        return $this->patientIds ??= $this->filtrerAnimaux(Patient::withoutGlobalScopes())->pluck('id')->all();
    }
}
