<?php

namespace App\Models;

use App\Enums\StatutSejour;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\SejourFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'clinique_id', 'patient_id', 'box', 'motif', 'statut',
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

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Vétérinaire référent. @return BelongsTo<Employe, $this> */
    public function veterinaire(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'veterinaire_id');
    }

    /** Facture du séjour, créée à la sortie. @return BelongsTo<Facture, $this> */
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

    /** @param Builder<self> $requete */
    public function scopeEnCours(Builder $requete): void
    {
        $requete->whereNull('sorti_le');
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
