<?php

namespace App\Models;

use Database\Factories\VaccinationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vaccination extends Model
{
    /** @use HasFactory<VaccinationFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id', 'produit_id', 'nom', 'valences', 'marque', 'lot', 'gtin', 'peremption_le', 'voie',
        'site_injection', 'remarque', 'reaction', 'employe_id', 'injectee_le', 'echeance_le',
    ];

    protected function casts(): array
    {
        return [
            'valences' => 'array',
            'injectee_le' => 'date',
            'echeance_le' => 'date',
            'peremption_le' => 'date',
        ];
    }

    /** @return BelongsTo<ProduitVeterinaire, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitVeterinaire::class, 'produit_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    /**
     * Les rappels partis pour cette injection (le carnet dit « Rappel envoyé »
     * d'après le serveur, plus seulement pendant la session, 01.11.2026).
     *
     * @return HasMany<RappelEnvoye, $this>
     */
    public function rappelsEnvoyes(): HasMany
    {
        return $this->hasMany(RappelEnvoye::class)->withoutGlobalScopes()->where('statut', 'envoye');
    }

    /**
     * Les injections pas encore renouvelées : aucune injection plus récente
     * du même vaccin pour l'animal (01.11.2026 : un vaccin renouvelé ne
     * compte plus comme un rappel dû dans la liste des patients).
     *
     * @param  Builder<Vaccination>  $requete
     */
    public function scopeNonRenouvelees(Builder $requete): void
    {
        $requete->whereRaw(self::SQL_NON_RENOUVELEE);
    }

    /** La même condition, en SQL, pour les comptes bruts (facettes) */
    public const string SQL_NON_RENOUVELEE = 'not exists (select 1 from vaccinations as plus_recente where plus_recente.patient_id = vaccinations.patient_id and lower(plus_recente.nom) = lower(vaccinations.nom) and (plus_recente.injectee_le > vaccinations.injectee_le or (plus_recente.injectee_le = vaccinations.injectee_le and plus_recente.id > vaccinations.id)))';
}
