<?php

namespace App\Models;

use Database\Factories\SejourSoinFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Soin du plan de soins d'un séjour. Enfant du dossier : pas de scope
 * propre, toujours résolu via le séjour. « Soin fait » horodate, signe
 * et sort le stock de la clinique du séjour si un produit est lié.
 */
class SejourSoin extends Model
{
    /** @use HasFactory<SejourSoinFactory> */
    use HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'fait' => false,
    ];

    protected $fillable = [
        'sejour_id', 'jour', 'prevu_a', 'acte', 'produit_id',
        'fait', 'fait_le', 'fait_par_id',
        // La ronde (03.11.2026) : administration d'un traitement, non donné, auteur figé
        'treatment_id', 'dose', 'done_by_name', 'skipped_at', 'skipped_by_id', 'skipped_by_name', 'skip_reason',
        'dose_amount', 'late_noted_at',
        // Deuxième vérification (03.11.2026) : annulation, prix et contenance figés
        'cancelled_at', 'cancelled_by_id', 'cancelled_by_name', 'cancelled_late', 'unit_price', 'content_per_unit', 'opening_number', 'dose_unit', 'is_unbilled',
    ];

    protected function casts(): array
    {
        return [
            'jour' => 'date',
            'prevu_a' => 'integer',
            'fait' => 'boolean',
            'fait_le' => 'datetime',
            'skipped_at' => 'datetime',
            'dose_amount' => 'float',
            'late_noted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_late' => 'boolean',
            'unit_price' => 'float',
            'content_per_unit' => 'float',
            'opening_number' => 'integer',
            'is_unbilled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }

    /** @return BelongsTo<StayTreatment, $this> */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(StayTreatment::class, 'treatment_id');
    }

    /** Noté « non donné » ou « non fait » */
    public function isSkipped(): bool
    {
        return $this->skipped_at !== null;
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * Les fois où elle a été donnée puis annulée (sixième vérification)
     *
     * @return HasMany<StayCareCancellation, $this>
     */
    public function cancellations(): HasMany
    {
        return $this->hasMany(StayCareCancellation::class)->orderBy('id');
    }
}
