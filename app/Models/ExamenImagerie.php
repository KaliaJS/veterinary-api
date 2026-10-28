<?php

namespace App\Models;

use App\Enums\ModaliteImagerie;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\ExamenImagerieFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Examen d'imagerie — PAR CLINIQUE, dossier métier maître de la
 * worklist (docs/data-model.md §10) : modalité, incidence, clichés
 * DICOM, compte rendu signé. Le RDV `imagerie` du planning, quand il
 * est lié, n'est que sa projection (même patron que le bloc) :
 * l'examen pousse ses horaires et sa clôture vers lui, jamais
 * l'inverse. Un compte rendu signé fige l'examen.
 */
class ExamenImagerie extends Model
{
    /** @use HasFactory<ExamenImagerieFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'examens_imagerie';

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'duree_minutes' => 30,
    ];

    protected $fillable = [
        'clinique_id', 'patient_id', 'rendez_vous_id', 'modalite',
        'libelle', 'incidence', 'realise_le', 'duree_minutes',
        'prescripteur_id', 'constatations', 'conclusion',
        'signe_par_id', 'signe_le', 'cree_par_id',
    ];

    protected function casts(): array
    {
        return [
            'modalite' => ModaliteImagerie::class,
            'realise_le' => 'datetime',
            'duree_minutes' => 'integer',
            'signe_le' => 'datetime',
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

    /** Projection planning de l'examen (RDV type `imagerie`).
     * @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** Prescripteur / opérateur.
     * @return BelongsTo<Employe, $this> */
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'prescripteur_id');
    }

    /** Signataire du compte rendu.
     * @return BelongsTo<Employe, $this> */
    public function signePar(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'signe_par_id');
    }

    /** @return HasMany<ClicheDicom, $this> */
    public function cliches(): HasMany
    {
        return $this->hasMany(ClicheDicom::class)->orderBy('id');
    }

    /** Diagnostics confirmés ou infirmés après coup (28.10.2026) @return HasMany<ConfirmationImagerie, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(ConfirmationImagerie::class)->orderBy('constate_le')->orderBy('id');
    }

    /** Compte rendu signé (`done` du front) — l'examen est figé. */
    public function estSigne(): bool
    {
        return $this->signe_le !== null;
    }
}
