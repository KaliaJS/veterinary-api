<?php

namespace App\Models;

use App\Enums\StatutIntervention;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\InterventionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Intervention du bloc opératoire — PAR CLINIQUE, dossier métier
 * maître du bloc (docs/data-model.md §11) : checklist de sécurité
 * bloquante avant l'incision, protocole anesthésique, journal du cycle,
 * note opératoire, consentement signé archivé au dossier du patient.
 * Le rendez-vous `chir` du planning, quand il est lié, n'est que sa
 * projection : l'intervention pousse ses horaires et sa clôture vers
 * lui, jamais l'inverse.
 */
class Intervention extends Model
{
    /** @use HasFactory<InterventionFactory> */
    use AppartientAUneClinique, HasFactory;

    /** Checklist de sécurité de base (CKL du front), code → libellé. */
    public const array CHECKLIST_DE_BASE = [
        'jeun' => 'À jeun depuis 12 h — vérifié auprès du propriétaire',
        'consent' => 'Consentement éclairé signé',
        'premed' => 'Prémédication administrée',
        'iv' => 'Voie veineuse posée + fluides prêts',
        'atb' => 'Antibioprophylaxie (si indiquée)',
        'mat' => 'Instrumentation stérile + compte des compresses',
    ];

    /** Protocole anesthésique proposé à la création (front). */
    public const array PROTOCOLE_PAR_DEFAUT = [
        'Prémédication — à définir avec l\'anesthésiste',
        'Induction — propofol IV titré',
        'Maintien — isoflurane · O₂ 100 %',
        'Analgésie — plan à confirmer',
    ];

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'statut' => 'prevu',
    ];

    protected $fillable = [
        'clinique_id', 'patient_id', 'salle_id', 'salle_nom',
        'chirurgien_id', 'rendez_vous_id', 'procedure', 'prevu_le',
        'duree_minutes', 'statut', 'protocole_anesthesique',
        'note_operatoire', 'incision_a', 'consentement_signe_le',
        'consentement_document_id', 'consentement_recueilli_par_id', 'cree_par_id',
    ];

    protected function casts(): array
    {
        return [
            'prevu_le' => 'datetime',
            'duree_minutes' => 'integer',
            'statut' => StatutIntervention::class,
            'protocole_anesthesique' => 'array',
            'incision_a' => 'datetime',
            'consentement_signe_le' => 'datetime',
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

    /** @return BelongsTo<Salle, $this> */
    public function salle(): BelongsTo
    {
        return $this->belongsTo(Salle::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function chirurgien(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'chirurgien_id');
    }

    /** Projection planning de l'intervention (RDV type `chir`).
     * @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** Consentement éclairé archivé au dossier du patient.
     * @return BelongsTo<Document, $this> */
    public function consentementDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'consentement_document_id');
    }

    /** @return HasMany<InterventionChecklistPoint, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(InterventionChecklistPoint::class)->orderBy('id');
    }

    /** @return HasMany<InterventionTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(InterventionTransition::class)->orderBy('id');
    }

    /** Crée les six points de base — appelé à la création du dossier. */
    public function creerChecklistDeBase(): void
    {
        foreach (self::CHECKLIST_DE_BASE as $code => $libelle) {
            $this->checklist()->create(['code' => $code, 'libelle' => $libelle]);
        }
    }

    /** L'incision ne part que checklist complète (points ajoutés compris). */
    public function checklistEstComplete(): bool
    {
        return ! $this->checklist()->where('est_coche', false)->exists();
    }

    public function estFinie(): bool
    {
        return $this->statut === StatutIntervention::Fini;
    }
}
