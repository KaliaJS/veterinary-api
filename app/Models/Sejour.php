<?php

namespace App\Models;

use App\Enums\StatutSejour;
use App\Events\StayUpdated;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Database\Factories\SejourFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Séjour d'hospitalisation — PAR CLINIQUE (le box est un lit
 * physique). Jamais supprimé : la sortie renseigne `sorti_le`, libère
 * le box et facture le séjour ; l'historique reste au dossier du
 * patient, comme les rendez-vous annulés (docs/data-model.md §8).
 */
class Sejour extends Model
{
    /** @use HasFactory<SejourFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'sejours';

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'statut' => 'stable',
    ];

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'admitted_by_name', 'box', 'motif', 'statut',
        'veterinaire_id', 'admis_le', 'sortie_prevue', 'sorti_le',
        'perfusion', 'note', 'facture_id', 'cree_par_id',
    ];

    protected function casts(): array
    {
        return [
            'box' => 'integer',
            'statut' => StatutSejour::class,
            'admis_le' => 'datetime',
            'sorti_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /**
     * Le propriétaire qui a confié l'animal, figé à l'admission (03.11.2026).
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Vétérinaire référent.
     *
     * @return BelongsTo<Employe, $this>
     */
    public function veterinaire(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'veterinaire_id');
    }

    /**
     * Facture du séjour, créée à la sortie.
     *
     * @return BelongsTo<Facture, $this>
     */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /** @return HasMany<SejourSoin, $this> */
    public function soins(): HasMany
    {
        return $this->hasMany(SejourSoin::class)->orderBy('jour')->orderBy('prevu_a')->orderBy('id');
    }

    /** @return HasMany<SejourConstante, $this> */
    public function constantes(): HasMany
    {
        return $this->hasMany(SejourConstante::class)->orderBy('jour')->orderBy('mesure_a')->orderBy('id');
    }

    /**
     * Traitements prescrits pendant le séjour (03.11.2026).
     *
     * @return HasMany<StayTreatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(StayTreatment::class)->orderBy('starts_at')->orderBy('id');
    }

    /**
     * La lettre de sortie (09.11.2026).
     *
     * @return HasOne<DischargeLetter, $this>
     */
    public function dischargeLetter(): HasOne
    {
        return $this->hasOne(DischargeLetter::class);
    }

    /** @return HasMany<StaySheet, $this> */
    public function sheets(): HasMany
    {
        return $this->hasMany(StaySheet::class)->orderBy('day');
    }

    /** @return HasMany<StayPainScore, $this> */
    public function painScores(): HasMany
    {
        return $this->hasMany(StayPainScore::class)->orderBy('day')->orderBy('minute')->orderBy('id');
    }

    /** @return HasMany<StayObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(StayObservation::class)->orderBy('day')->orderBy('minute')->orderBy('id');
    }

    /** @param Builder<self> $requete */
    public function scopeEnCours(Builder $requete): void
    {
        $requete->whereNull('sorti_le');
    }

    /**
     * Les autres postes de la clinique relisent ce séjour (Reverb,
     * 03.11.2026), après la transaction et jamais au prix du geste.
     */
    public static function announce(int $id, int $cliniqueId): void
    {
        // Aux autres postes seulement (X-Socket-ID) : celui qui fait le geste a déjà relu
        TempsReel::diffuser(function () use ($id, $cliniqueId): void {
            broadcast(new StayUpdated($id, $cliniqueId))->toOthers();
        });
    }

    public function estSorti(): bool
    {
        return $this->sorti_le !== null;
    }

    /**
     * Jours facturés : jours calendaires locaux couverts, de
     * l'admission à la sortie (admis et sorti aujourd'hui = 1 jour,
     * admis hier = 2 — parité front).
     */
    public function joursFactures(): int
    {
        $fuseau = $this->clinique->fuseau_horaire ?? 'Europe/Zurich';
        $fin = $this->sorti_le ?? now();

        return (int) $this->admis_le->copy()->setTimezone($fuseau)->startOfDay()
            ->diffInDays($fin->copy()->setTimezone($fuseau)->startOfDay()) + 1;
    }
}
