<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le rapport de bloc d'une intervention (09.11.2026). `draft` : le
 * brouillon de l'IA (sources numérotées, phrases et sources, doutes) ;
 * `sections` : le texte relu, par rubrique. Rien n'est au rapport avant la
 * validation, qui le fige. Brouillon et texte chiffrés en base.
 */
class SurgeryReport extends Model
{
    protected $table = 'surgery_reports';

    protected $fillable = ['intervention_id', 'status', 'error', 'draft', 'sections', 'writings', 'written_at', 'validated_at', 'validated_by_id', 'validated_by_name'];

    protected $attributes = ['status' => 'empty'];

    /** Les rubriques, dans l'ordre du rapport */
    public const SECTIONS = ['intervention', 'anaesthesia', 'technique', 'material', 'complications', 'aftercare', 'implants'];

    public const SECTION_LABELS = [
        'intervention' => 'Intervention',
        'anaesthesia' => 'Anesthésie',
        'technique' => 'Technique et constatations',
        'material' => 'Matériel et sutures',
        'complications' => 'Complications',
        'aftercare' => 'Consignes après l’opération',
        'implants' => 'Implants',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'encrypted:array',
            'sections' => 'encrypted:array',
            'written_at' => 'datetime',
            'validated_at' => 'datetime',
            'writings' => 'integer',
        ];
    }

    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    /** @return HasMany<SurgeryReportComplement, $this> */
    public function complements(): HasMany
    {
        return $this->hasMany(SurgeryReportComplement::class)->orderBy('id');
    }

    public function isValidated(): bool
    {
        return $this->status === 'validated';
    }

    /**
     * Toutes les rubriques, vides comprises
     *
     * @param  array<string, mixed>|null  $sections
     * @return array<string, string>
     */
    public static function normalizeSections(?array $sections): array
    {
        return collect(self::SECTIONS)->mapWithKeys(fn (string $k) => [$k => trim((string) ($sections[$k] ?? ''))])->all();
    }
}
