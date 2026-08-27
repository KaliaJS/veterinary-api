<?php

namespace App\Models;

use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Services\PrestationsParDefaut;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected $fillable = [
        'nom', 'plan', 'cycle', 'resilie_le', 'moyen_paiement',
        'usage_emails', 'usage_sms', 'usage_stockage_go',
        'quota_extra_emails', 'quota_extra_sms', 'quota_extra_stockage_go',
    ];

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'plan' => 'cabinet',
        'cycle' => 'mois',
        'usage_emails' => 0,
        'usage_sms' => 0,
        'usage_stockage_go' => 0,
        'quota_extra_emails' => 0,
        'quota_extra_sms' => 0,
        'quota_extra_stockage_go' => 0,
    ];

    protected function casts(): array
    {
        return [
            'plan' => FormuleSaas::class,
            'resilie_le' => 'date',
            'usage_emails' => 'integer',
            'usage_sms' => 'integer',
            'usage_stockage_go' => 'decimal:1',
            'quota_extra_emails' => 'integer',
            'quota_extra_sms' => 'integer',
            'quota_extra_stockage_go' => 'integer',
        ];
    }

    /**
     * Les rôles par défaut et le catalogue de prestations naissent avec
     * l'organisation (les seeders, qui coupent les événements de
     * modèle, les appellent explicitement).
     */
    protected static function booted(): void
    {
        static::created(function (Organisation $organisation): void {
            RolesParDefaut::creer($organisation);
            PrestationsParDefaut::creer($organisation);
        });
    }

    /** @return HasMany<Societe, $this> */
    public function societes(): HasMany
    {
        return $this->hasMany(Societe::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasManyThrough<Clinique, Societe, $this> */
    public function cliniques(): HasManyThrough
    {
        return $this->hasManyThrough(Clinique::class, Societe::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** Factures d'abonnement et de packs (« F- »).
     * @return HasMany<OrganisationFacture, $this> */
    public function facturesAbonnement(): HasMany
    {
        return $this->hasMany(OrganisationFacture::class);
    }

    /**
     * Employés ACTIFS (accès non suspendu), toutes cliniques de
     * l'organisation — la grandeur plafonnée par la formule.
     */
    public function employesActifs(): int
    {
        return Employe::query()
            ->withoutGlobalScope('clinique')
            ->whereIn('clinique_id', $this->cliniques()->pluck('cliniques.id'))
            ->where('acces_suspendu', false)
            ->count();
    }

    /**
     * Limites dépassées pour une formule cible (overLims du front) —
     * vide si le changement est possible.
     *
     * @return list<string>
     */
    public function depassementsPour(FormuleSaas $cible): array
    {
        $limites = $cible->limites();
        $problemes = [];

        $cliniques = $this->cliniques()->count();
        if ($limites['cliniques'] !== null && $cliniques > $limites['cliniques']) {
            $problemes[] = "{$cliniques} cliniques (max {$limites['cliniques']})";
        }

        $employes = $this->employesActifs();
        if ($limites['employes'] !== null && $employes > $limites['employes']) {
            $problemes[] = "{$employes} employés actifs (max {$limites['employes']})";
        }

        $patients = $this->patients()->count();
        if ($limites['patients'] !== null && $patients > $limites['patients']) {
            $problemes[] = "{$patients} patients (max {$limites['patients']})";
        }

        if ($limites['stockage_go'] !== null && (float) $this->usage_stockage_go > $limites['stockage_go']) {
            $problemes[] = "{$this->usage_stockage_go} Go de stockage (max {$limites['stockage_go']} Go)";
        }

        return $problemes;
    }

    /** La formule plafonne les employés actifs — garde de l'embauche et du rétablissement d'accès. */
    public function limiteEmployesAtteinte(): bool
    {
        $limite = $this->plan->limites()['employes'];

        return $limite !== null && $this->employesActifs() >= $limite;
    }
}
