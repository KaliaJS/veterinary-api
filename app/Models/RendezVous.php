<?php

namespace App\Models;

use App\Enums\DrapeauRendezVous;
use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use Database\Factories\RendezVousFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jamais supprimé : l'annulation est le statut `annule` (auteur,
 * horodatage et motif dans l'audit). Le chevauchement d'intervalles est
 * autorisé — aucune contrainte d'exclusivité (docs/data-model.md §3).
 */
class RendezVous extends Model
{
    /** @use HasFactory<RendezVousFactory> */
    use HasFactory;

    protected $table = 'rendez_vous';

    protected $fillable = [
        'clinique_id', 'ligne_planning_id', 'patient_id', 'client_id',
        'salle_id', 'patient_name', 'species', 'owner_name', 'type',
        'status', 'flags', 'reason', 'starts_at', 'ends_at', 'arrived_at',
    ];

    protected function casts(): array
    {
        return [
            'species' => Espece::class,
            'type' => TypeRendezVous::class,
            'status' => StatutRendezVous::class,
            'flags' => AsEnumCollection::of(DrapeauRendezVous::class),
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'arrived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<LignePlanning, $this> */
    public function lignePlanning(): BelongsTo
    {
        return $this->belongsTo(LignePlanning::class, 'ligne_planning_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Salle, $this> */
    public function salle(): BelongsTo
    {
        return $this->belongsTo(Salle::class);
    }

    /** @return HasMany<RendezVousAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(RendezVousAudit::class, 'rendez_vous_id');
    }

    /** Créneau réservé sans patient — jamais facturé, non déplaçable. */
    public function estTampon(): bool
    {
        return $this->type === TypeRendezVous::Tampon;
    }
}
