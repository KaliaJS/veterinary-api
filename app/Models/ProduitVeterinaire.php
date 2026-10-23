<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Médicament vétérinaire autorisé en Suisse (catalogue Swissmedic, enrichi
 * des GTIN Refdata et, pour les vaccins, des valences et du schéma curés).
 * Modèle de niveau plateforme : aucune organisation, aucun scope.
 */
class ProduitVeterinaire extends Model
{
    use HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'liberation_lot' => false,
    ];

    public const VACCIN = 'vaccin';

    public const IMMUNOLOGIQUE = 'immunologique';

    public const MEDICAMENT = 'medicament';

    protected $table = 'produits_veterinaires';

    protected $fillable = [
        'numero_autorisation', 'nom', 'nom_court', 'forme', 'atc_code', 'categorie', 'titulaire', 'statut',
        'premiere_autorisation_le', 'expire_le', 'indication', 'liberation_lot', 'categorie_remise',
        'especes', 'voies', 'source_le', 'retire_le',
    ];

    protected function casts(): array
    {
        return [
            'premiere_autorisation_le' => 'date',
            'expire_le' => 'date',
            'liberation_lot' => 'boolean',
            'especes' => 'array',
            'voies' => 'array',
            'source_le' => 'date',
            'retire_le' => 'date',
        ];
    }

    /** @return HasMany<ProduitEmballage, $this> */
    public function emballages(): HasMany
    {
        return $this->hasMany(ProduitEmballage::class, 'produit_id');
    }

    /** @return BelongsToMany<Valence, $this> */
    public function valences(): BelongsToMany
    {
        return $this->belongsToMany(Valence::class, 'produit_valence', 'produit_id', 'valence_id')->orderBy('nom');
    }

    /** @return HasOne<VaccinSchema, $this> */
    public function schema(): HasOne
    {
        return $this->hasOne(VaccinSchema::class, 'produit_id');
    }

    /** @return HasMany<Vaccination, $this> */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class, 'produit_id');
    }

    public function estVaccin(): bool
    {
        return in_array($this->categorie, [self::VACCIN, self::IMMUNOLOGIQUE], true);
    }
}
