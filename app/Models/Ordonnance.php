<?php

namespace App\Models;

use App\Enums\StatutOrdonnance;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\OrdonnanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'ordonnance suit le patient — NIVEAU ORGANISATION — et garde la
 * clinique de l'acte pour la sortie de stock. Jamais modifiée après
 * signature : le renouvellement crée une nouvelle ordonnance et clôt la
 * précédente ; la suppression n'existe que dans la fenêtre d'annulation
 * d'une signature (le stock est alors restauré).
 */
class Ordonnance extends Model
{
    /** @use HasFactory<OrdonnanceFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'numero', 'patient_id', 'employe_id',
        'signee_le', 'renouvellements', 'statut', 'note', 'ordonnance_precedente_id',
    ];

    protected function casts(): array
    {
        return [
            'signee_le' => 'date',
            'renouvellements' => 'integer',
            'statut' => StatutOrdonnance::class,
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
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

    /** @return HasMany<OrdonnanceLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(OrdonnanceLigne::class);
    }

    /**
     * Demandes de renouvellement déposées par le propriétaire depuis
     * son espace (en attente tant que `traitee_le` est nul).
     *
     * @return HasMany<DemandeRenouvellementOrdonnance, $this>
     */
    public function demandesRenouvellement(): HasMany
    {
        return $this->hasMany(DemandeRenouvellementOrdonnance::class);
    }

    /** Ordonnance que ce renouvellement a clôturée.
     * @return BelongsTo<Ordonnance, $this> */
    public function precedente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ordonnance_precedente_id');
    }
}
