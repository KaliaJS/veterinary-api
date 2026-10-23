<?php

namespace App\Models;

use App\Enums\ServiceClinique;
use App\Models\Concerns\AUnePhoto;
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
    use AUnePhoto, HasFactory;

    protected $fillable = [
        'societe_id', 'nom', 'raison_sociale', 'rue', 'npa', 'ville',
        'latitude', 'longitude', 'tel', 'email', 'fuseau_horaire', 'nombre_boxes', 'affiche_veterinaires', 'rdv_sans_compte', 'rdv_a_valider', 'urgences_a_valider',
        'acompte_centimes', 'paiements_integres',
        'stripe_location_id', 'photo_chemin', 'photo_apercu',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:5',
            'longitude' => 'decimal:5',
            'nombre_boxes' => 'integer',
            'affiche_veterinaires' => 'boolean',
            'rdv_sans_compte' => 'boolean',
            'rdv_a_valider' => 'boolean',
            'urgences_a_valider' => 'boolean',
            'acompte_centimes' => 'integer',
            'paiements_integres' => 'boolean',
        ];
    }

    /**
     * La clinique propose-t-elle ce service ? (relation chargée si elle
     * l'est, sinon une requête)
     */
    public function aService(ServiceClinique $service): bool
    {
        return $this->relationLoaded('services')
            ? $this->services->contains(fn (CliniqueService $ligne): bool => $ligne->service === $service)
            : $this->services()->where('service', $service)->exists();
    }

    /** @return HasMany<CliniqueService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(CliniqueService::class);
    }

    /** @return HasMany<CliniqueEspece, $this> */
    public function especes(): HasMany
    {
        return $this->hasMany(CliniqueEspece::class);
    }

    /** Cliniques des organisations ouvertes (vérifiées et payées) : annuaire, boutiques, demandes.
     * @param  Builder<Clinique>  $requete */
    /**
     * Ce qui manque pour paraître dans l'annuaire des cliniques : une
     * photo, l'adresse complète et sa position sur la carte. Sans rien de
     * manquant, la clinique y est.
     *
     * @return list<string> 'photo', 'adresse', 'position'
     */
    public function manquesAnnuaire(): array
    {
        return array_values(array_filter([
            $this->photo_chemin === null ? 'photo' : null,
            blank($this->rue) || blank($this->npa) || blank($this->ville) ? 'adresse' : null,
            $this->latitude === null || $this->longitude === null ? 'position' : null,
        ]));
    }

    public function estDansLAnnuaire(): bool
    {
        return $this->manquesAnnuaire() === [];
    }

    /** Cliniques qui proposent ce service.
     * @param  Builder<Clinique>  $requete */
    public function scopeAvecService(Builder $requete, ServiceClinique $service): void
    {
        $requete->whereHas('services', fn (Builder $services) => $services->where('service', $service));
    }

    public function scopeOuvertes(Builder $requete): void
    {
        $requete->whereHas('societe.organisation', fn (Builder $organisation) => $organisation->ouvertes());
    }

    /** L'acompte demandé pour réserver en ligne, si la société peut l'encaisser (sinon aucun). */
    public function acompteExigible(): ?int
    {
        return $this->acompte_centimes > 0 && $this->encaisseAvecVeterinary() ? $this->acompte_centimes : null;
    }

    /**
     * Cartes, TWINT et acomptes passent par Veterinary : la clinique n'a pas
     * choisi de garder son propre terminal et le compte de la société encaisse.
     * Sinon la caisse marque les paiements par carte et TWINT comme payés.
     */
    public function encaisseAvecVeterinary(): bool
    {
        return $this->paiements_integres !== false && (bool) $this->societe?->encaisseEnLigne();
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
