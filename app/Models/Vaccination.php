<?php

namespace App\Models;

use App\Services\EtatVaccinal;
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
            'renouvelee' => 'boolean',
        ];
    }

    /** Chaque injection ajoutée, changée ou retirée refait les « renouvelée » de l'animal (01.11.2026) */
    protected static function booted(): void
    {
        static::saved(function (Vaccination $vaccination): void {
            self::recalculerRenouvellements($vaccination->patient_id);
            // Passée à un autre animal : l'ancien se refait aussi
            if ($vaccination->wasChanged('patient_id')) {
                self::recalculerRenouvellements($vaccination->getOriginal('patient_id'));
            }
        });
        static::deleted(fn (Vaccination $vaccination) => self::recalculerRenouvellements($vaccination->patient_id));
    }

    /** `renouvelee` d'après la règle unique (EtatVaccinal::renouvelees), sans événements */
    public static function recalculerRenouvellements(?int $patientId): void
    {
        if ($patientId === null) {
            return;
        }
        $injections = self::query()->withoutGlobalScopes()->with('produit.valences')->where('patient_id', $patientId)->get();
        $renouvelees = EtatVaccinal::renouvelees($injections);
        foreach ($injections as $injection) {
            $doit = isset($renouvelees[$injection->id]);
            if ((bool) $injection->getAttribute('renouvelee') !== $doit) {
                self::query()->withoutGlobalScopes()->whereKey($injection->id)->update(['renouvelee' => $doit]);
            }
        }
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
     * Les injections pas encore renouvelées (01.11.2026) : `renouvelee`,
     * tenu à jour à chaque injection d'après la règle unique
     * (EtatVaccinal::renouvelees : même vaccin, ou combiné qui le couvre).
     *
     * @param  Builder<Vaccination>  $requete
     */
    public function scopeNonRenouvelees(Builder $requete): void
    {
        $requete->whereRaw(self::SQL_NON_RENOUVELEE);
    }

    /** La même condition, en SQL, pour les comptes bruts (facettes) */
    public const string SQL_NON_RENOUVELEE = 'vaccinations.renouvelee = 0';
}
