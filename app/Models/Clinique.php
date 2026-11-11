<?php

namespace App\Models;

use App\Enums\ServiceClinique;
use App\Models\Concerns\AUnePhoto;
use App\Support\JoursFeries;
use App\Support\Localites;
use App\Support\LogosOrganisation;
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

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'affiche_veterinaires' => true,
        'rdv_sans_compte' => false,
        'rdv_a_valider' => false,
        'urgences_a_valider' => true,
        'paiements_integres' => true,
    ];

    protected $fillable = [
        'societe_id', 'nom', 'raison_sociale', 'rue', 'npa', 'ville',
        'latitude', 'longitude', 'tel', 'email', 'fuseau_horaire', 'nombre_boxes', 'affiche_veterinaires', 'rdv_sans_compte', 'rdv_a_valider', 'urgences_a_valider',
        'acompte_centimes', 'paiements_integres', 'responsable_stupefiants_id',
        'stripe_location_id', 'photo_chemin', 'photo_apercu',
        'jours_feries_variante', 'jours_feries_retires', 'jours_feries_ajoutes',
    ];

    /** @var array<int, array<string, array{cle: string, nom: string, part: float}>> Les jours fériés déjà calculés, par année */
    private array $joursFeriesParAnnee = [];

    protected function casts(): array
    {
        return [
            'jours_feries_retires' => 'array',
            'jours_feries_ajoutes' => 'array',
            'latitude' => 'decimal:5',
            'longitude' => 'decimal:5',
            'nombre_boxes' => 'integer',
            'affiche_veterinaires' => 'boolean',
            'rdv_sans_compte' => 'boolean',
            'rdv_a_valider' => 'boolean',
            'urgences_a_valider' => 'boolean',
            'acompte_centimes' => 'integer',
            'paiements_integres' => 'boolean',
            'rappels_email' => 'boolean',
            'rappels_sms' => 'boolean',
            'rappels_delai_jours' => 'integer',
            'rappels_vaccins' => 'boolean',
            'rappels_controles' => 'boolean',
            'relance_noshow' => 'boolean',
            'rappels_rendez_vous' => 'boolean',
            'relance_vaccin_retard' => 'boolean',
            'relance_devis' => 'boolean',
            'messages_recus' => 'boolean',
            'messages_reponses_seulement' => 'boolean',
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

    /**
     * Cliniques dont l'organisation est ouverte (vérifiée et payée).
     *
     * @param  Builder<Clinique>  $requete
     */
    public function scopeOuvertes(Builder $requete): void
    {
        $requete->whereHas('societe.organisation', /** @param  Builder<Organisation>  $organisation */ fn (Builder $organisation) => $organisation->ouvertes());
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

    /**
     * En-tête des pièces émises par la clinique (factures, devis,
     * ordonnances, registres) : son nom, son adresse, ses contacts et la
     * société qui facture, avec son numéro IDE (23.10.2026).
     *
     * @return array{nom: string, adresse: string, contact: string, societe: ?string, ide: ?string, tva: bool, logo_url: ?string}
     */
    public function enTeteDocument(): array
    {
        return [
            'nom' => $this->raison_sociale ?: $this->nom,
            'adresse' => implode(', ', array_filter([$this->rue, trim(($this->npa ?? '').' '.($this->ville ?? ''))])),
            'contact' => implode(' · ', array_filter([$this->tel, $this->email])),
            'societe' => $this->societe?->nom,
            'ide' => $this->societe?->ide,
            'tva' => $this->societe?->assujettie_tva !== false,
            // Un seul logo, celui du site web de l'organisation (28.10.2026)
            'logo_url' => app(LogosOrganisation::class)->pour($this->societe?->organisation_id),
        ];
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

    /** Le canton de la clinique, d'après son NPA (répertoire de swisstopo), ou null. */
    public function canton(): ?string
    {
        return Localites::canton($this->npa);
    }

    /**
     * Ses jours fériés d'une année (É23) : ceux de son canton et de sa
     * région (annexe du SECO), moins les jours qu'elle travaille, plus
     * ceux qu'elle chôme en plus.
     *
     * @return array<string, array{cle: string, nom: string, part: float}>
     */
    public function joursFeries(int $annee): array
    {
        return $this->joursFeriesParAnnee[$annee] ??= JoursFeries::annee(
            $this->canton(),
            $this->jours_feries_variante,
            $annee,
            $this->jours_feries_retires ?? [],
            $this->jours_feries_ajoutes ?? [],
        );
    }
}
