<?php

namespace App\Models;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une déclaration d'antibiotiques à l'OSAV (SI ABV, 08.11.2026), née d'une
 * ordonnance (prescrit, remis, utilisé pendant la consultation) ou d'un
 * traitement d'hospitalisation (utilisé à la clinique).
 */
class AntibioticDeclaration extends Model
{
    use AppartientAUneClinique;

    protected $fillable = [
        'clinique_id', 'recipe_number', 'form', 'status', 'source_type', 'source_id', 'patient_id', 'employe_id', 'visit_on',
        'species_code', 'weight_kg', 'diagnosis_key', 'diagnosis_description', 'is_antibiogram_done', 'animals_count', 'animals_total',
        'holding_number', 'holding_address', 'postcode', 'identification', 'additional_instructions', 'av_treatment_days', 'av_daily_dose',
        'osav_uuid', 'osav_message', 'ignored_fields', 'sent_at', 'sent_appendix', 'deactivated_at', 'is_outcome_unknown', 'attempts', 'replaces_id',
    ];

    protected function casts(): array
    {
        return [
            'form' => IsabvForm::class,
            'status' => DeclarationStatus::class,
            'visit_on' => 'date',
            'weight_kg' => 'float',
            'is_antibiogram_done' => 'boolean',
            'animals_count' => 'integer',
            'animals_total' => 'integer',
            'av_treatment_days' => 'integer',
            'av_daily_dose' => 'float',
            'ignored_fields' => 'array',
            'sent_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'is_outcome_unknown' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    /** Remplacée, partie sous un autre complément que l'actuel : Veterinary ne peut pas la désactiver, le vétérinaire le fait sur le site de l'OSAV */
    public function isDeactivatedOnlyOnWeb(): bool
    {
        if ($this->status !== DeclarationStatus::Replaced || $this->sent_appendix === null) {
            return false;
        }

        return $this->sent_appendix !== (string) IsabvConnection::withoutGlobalScopes()->where('clinique_id', $this->clinique_id)->value('appendix');
    }

    /** @return HasMany<AntibioticDeclarationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AntibioticDeclarationLine::class, 'declaration_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
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

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<AntibioticDeclaration, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }
}
