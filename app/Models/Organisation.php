<?php

namespace App\Models;

use App\Auth\RolesParDefaut;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected $fillable = ['nom'];

    /**
     * Les rôles par défaut naissent avec l'organisation (les seeders,
     * qui coupent les événements de modèle, appellent RolesParDefaut
     * explicitement).
     */
    protected static function booted(): void
    {
        static::created(function (Organisation $organisation): void {
            RolesParDefaut::creer($organisation);
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
}
