<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Enums\DrapeauRendezVous;
use App\Enums\Espece;
use App\Enums\StatutIntervention;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Events\RendezVousMisAJour;
use App\Events\RendezVousSupprime;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Database\Factories\RendezVousFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jamais supprimé : l'annulation est le statut `annule` (auteur,
 * horodatage et motif dans l'audit). Le chevauchement d'intervalles est
 * autorisé — aucune contrainte d'exclusivité (docs/data-model.md §3).
 *
 * @property Collection<int, DrapeauRendezVous>|null $flags (AsEnumCollection, que Larastan ne lit pas)
 */
class RendezVous extends Model
{
    /** @use HasFactory<RendezVousFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'rendez_vous';

    /**
     * Pris, déplacé, annulé, modifié ou retiré : diffusé à la clinique
     * (Reverb, 28.10.2026), une fois la transaction validée.
     */
    protected static function booted(): void
    {
        static::saved(function (RendezVous $rendezVous): void {
            TempsReel::diffuser(function () use ($rendezVous): void {
                $frais = RendezVous::withoutGlobalScopes()->find($rendezVous->id);
                if ($frais !== null) {
                    RendezVousMisAJour::dispatch($frais);
                }
            });
        });
        static::deleted(fn (RendezVous $rendezVous) => TempsReel::diffuser(fn () => RendezVousSupprime::dispatch($rendezVous->id, (int) $rendezVous->clinique_id)));
    }

    protected $fillable = [
        'clinique_id', 'ligne_planning_id', 'patient_id', 'client_id',
        'salle_id', 'patient_name', 'species', 'owner_name', 'type',
        'status', 'flags', 'reason', 'note_accueil', 'starts_at', 'ends_at', 'arrived_at',
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

    /**
     * Le geste métier qui termine ce rendez-vous projeté, s'il attend
     * encore (23.10.2026) : signer le compte rendu d'imagerie, finir
     * l'intervention, clore la téléconsultation. Un « fini » posé à la
     * main les court-circuiterait (facture sans acte, examen sans lecture).
     */
    public function gesteEnAttente(): ?string
    {
        return match (true) {
            ExamenImagerie::withoutGlobalScopes()->where('rendez_vous_id', $this->id)->whereNull('signe_le')->exists() => 'Ce rendez-vous se termine par la signature du compte rendu d’imagerie.',
            Intervention::withoutGlobalScopes()->where('rendez_vous_id', $this->id)->where('statut', '!=', StatutIntervention::Fini)->exists() => 'Ce rendez-vous se termine avec l’intervention, au bloc.',
            SessionTeleconsultation::withoutGlobalScopes()->where('rendez_vous_id', $this->id)->whereNull('terminee_le')->exists() => 'Ce rendez-vous se termine à la fin de la téléconsultation.',
            // Jamais commencée (24.10.2026) : ni terminée ni facturée à la main
            $this->type === TypeRendezVous::Teleconsultation
                && SessionTeleconsultation::withoutGlobalScopes()->where('rendez_vous_id', $this->id)->doesntExist() => 'Cette téléconsultation n’a pas eu lieu : marquez-la non venue, ou annulez-la.',
            default => null,
        };
    }

    /** Compte rendu SOAP (0..1 — docs/data-model.md §13).
     * @return HasOne<CompteRendu, $this> */
    public function compteRendu(): HasOne
    {
        return $this->hasOne(CompteRendu::class, 'rendez_vous_id');
    }

    /**
     * La facture du rendez-vous (une seule par rendez-vous), toutes cliniques :
     * pour savoir si un acompte est déjà déduit.
     *
     * @return HasOne<Facture, $this>
     */
    public function facture(): HasOne
    {
        return $this->hasOne(Facture::class, 'rendez_vous_id')->withoutGlobalScopes();
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
            ->withExists('demandeEnLigne')
            ->addSelect(['no_shows_client' => RendezVous::query()
                ->withoutGlobalScope('clinique')
                ->from('rendez_vous as no_shows')
                ->selectRaw('count(*)')
                ->whereColumn('no_shows.client_id', 'rendez_vous.client_id')
                ->whereIn('no_shows.clinique_id', self::cliniquesDe(app(ContexteClinique::class)->organisationId()))
                ->where('no_shows.status', StatutRendezVous::Noshow),
            ]);
    }

    /**
     * Les cliniques d'une organisation (sous-requête) : un compteur de
     * no-shows ne compte jamais ceux d'une autre organisation.
     */
    public static function cliniquesDe(?int $organisationId): \Illuminate\Database\Query\Builder
    {
        return DB::table('cliniques')
            ->join('societes', 'societes.id', '=', 'cliniques.societe_id')
            ->where('societes.organisation_id', $organisationId)
            ->select('cliniques.id');
    }

    /** Compteur de no-shows du client — même dérivé, pour un modèle isolé. */
    public function noShowsDuClient(): int
    {
        if ($this->client_id === null) {
            return 0;
        }

        $organisationId = Client::withoutGlobalScopes()->whereKey($this->client_id)->value('organisation_id');

        return RendezVous::withoutGlobalScope('clinique')
            ->where('client_id', $this->client_id)
            ->whereIn('clinique_id', self::cliniquesDe($organisationId))
            ->where('status', StatutRendezVous::Noshow)
            ->count();
    }
}
