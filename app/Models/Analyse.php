<?php

namespace App\Models;

use App\Enums\PrioriteAnalyse;
use App\Enums\StatutAnalyse;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\AnalyseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande d'analyses du laboratoire — PAR CLINIQUE (docs/data-model.md
 * §9), numérotée A-YY-NNNN. Cycle attente → cours → valid → ok un cran
 * à la fois ; les résultats (bornes instantanées du référentiel) se
 * saisissent pendant l'analyse, la validation les signe et les fige.
 * Jamais de suppression.
 */
class Analyse extends Model
{
    /** @use HasFactory<AnalyseFactory> */
    use AppartientAUneClinique, HasFactory;

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'statut' => 'attente',
        'priorite' => 'std',
    ];

    protected $fillable = [
        'clinique_id', 'patient_id', 'numero', 'statut', 'priorite',
        'preleve_le', 'prescripteur_id', 'valide_par_id', 'valide_le',
        'note', 'cree_par_id',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutAnalyse::class,
            'priorite' => PrioriteAnalyse::class,
            'preleve_le' => 'datetime',
            'valide_le' => 'datetime',
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

    /** @return BelongsTo<Employe, $this> */
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'prescripteur_id');
    }

    /** Signataire de la validation.
     * @return BelongsTo<Employe, $this> */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'valide_par_id');
    }

    /** @return HasMany<AnalysePanel, $this> */
    public function panels(): HasMany
    {
        return $this->hasMany(AnalysePanel::class)->orderBy('id');
    }

    /** @return HasMany<AnalyseResultat, $this> */
    public function resultats(): HasMany
    {
        return $this->hasMany(AnalyseResultat::class)->orderBy('id');
    }

    public function estValidee(): bool
    {
        return $this->statut === StatutAnalyse::Valide;
    }
}
