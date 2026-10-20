<?php

namespace App\Models;

use Database\Factories\CliniqueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Clinique extends Model
{
    /** @use HasFactory<CliniqueFactory> */
    use HasFactory;

    protected $fillable = [
        'societe_id', 'nom', 'raison_sociale', 'rue', 'npa', 'ville',
        'latitude', 'longitude', 'tel', 'email', 'fuseau_horaire', 'nombre_boxes',
        'stripe_location_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:5',
            'longitude' => 'decimal:5',
            'nombre_boxes' => 'integer',
        ];
    }

    /** Cliniques des organisations ouvertes (vérifiées et payées) : annuaire, boutiques, demandes.
     * @param  Builder<Clinique>  $requete */
    public function scopeOuvertes(Builder $requete): void
    {
        $requete->whereHas('societe.organisation', fn (Builder $organisation) => $organisation->ouvertes());
    }

    /** @return BelongsTo<Societe, $this> */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /** @return HasOne<HoraireOuverture, $this> */
    public function horaireOuverture(): HasOne
    {
        return $this->hasOne(HoraireOuverture::class);
    }

    /** @return HasMany<Salle, $this> */
    public function salles(): HasMany
    {
        return $this->hasMany(Salle::class);
    }

    /**
     * Employés affectés à la clinique. Le scope de clinique des employés
     * vise celle du jeton : le lever pour lire une clinique sœur.
     *
     * @return BelongsToMany<Employe, $this>
     */
    public function employes(): BelongsToMany
    {
        return $this->belongsToMany(Employe::class)->withTimestamps();
    }

    /** @return HasMany<LignePlanning, $this> */
    public function lignesPlanning(): HasMany
    {
        return $this->hasMany(LignePlanning::class);
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }
}
