<?php

namespace App\Models;

use App\Enums\DrapeauRendezVous;
use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\RendezVousFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jamais supprimé : l'annulation est le statut `annule` (auteur,
 * horodatage et motif dans l'audit). Le chevauchement d'intervalles est
 * autorisé — aucune contrainte d'exclusivité (docs/data-model.md §3).
 */
class RendezVous extends Model
{
    /** @use HasFactory<RendezVousFactory> */
    use AppartientAUneClinique, HasFactory;

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

    /** Compte rendu SOAP (0..1 — docs/data-model.md §13).
     * @return HasOne<CompteRendu, $this> */
    public function compteRendu(): HasOne
    {
        return $this->hasOne(CompteRendu::class, 'rendez_vous_id');
    }

    /** La demande en ligne qui l'a réservé (acompte compris), s'il y en a une.
     * @return HasOne<DemandeRendezVous, $this> */
    public function demandeEnLigne(): HasOne
    {
        return $this->hasOne(DemandeRendezVous::class, 'rendez_vous_id')->withoutGlobalScopes();
    }

    /** Créneau réservé sans patient — jamais facturé, non déplaçable. */
    public function estTampon(): bool
    {
        return $this->type === TypeRendezVous::Tampon;
    }

    /**
     * Dérivés d'affichage servis avec la liste (une requête pour toute
     * la page) : compte rendu rédigé, compteur de no-shows du client —
     * dérivé, jamais stocké, toutes cliniques de l'organisation (le
     * client est d'organisation), homonymes distingués par la FK.
     *
     * @param  Builder<RendezVous>  $query
     * @return Builder<RendezVous>
     */
    public function scopeAvecDerives(Builder $query): Builder
    {
        return $query
            ->withExists('compteRendu')
            ->addSelect(['no_shows_client' => RendezVous::query()
                ->withoutGlobalScope('clinique')
                ->from('rendez_vous as no_shows')
                ->selectRaw('count(*)')
                ->whereColumn('no_shows.client_id', 'rendez_vous.client_id')
                ->where('no_shows.status', StatutRendezVous::Noshow),
            ]);
    }

    /** Compteur de no-shows du client — même dérivé, pour un modèle isolé. */
    public function noShowsDuClient(): int
    {
        if ($this->client_id === null) {
            return 0;
        }

        return RendezVous::withoutGlobalScope('clinique')
            ->where('client_id', $this->client_id)
            ->where('status', StatutRendezVous::Noshow)
            ->count();
    }
}
