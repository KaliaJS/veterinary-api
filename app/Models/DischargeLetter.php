<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La lettre de sortie d'un séjour (choix de Jim, 09.11.2026), pour le
 * propriétaire. `home_notes` : les consignes pour la maison tapées par le
 * vétérinaire ; `draft` : le brouillon de l'IA (sources numérotées, phrases
 * et sources, doutes) ; `sections` : le texte relu, par rubrique ;
 * `copied` : médicaments, contrôle et clinique figés à la signature. Rien
 * n'est à la lettre avant la signature, qui la fige. Chiffrés en base.
 */
class DischargeLetter extends Model
{
    protected $table = 'discharge_letters';

    protected $fillable = [
        'sejour_id', 'status', 'error', 'home_notes', 'draft', 'sections', 'edited_sections', 'edited_at', 'copied',
        'writings', 'is_redraft_pending', 'written_at', 'signed_at', 'signed_by_id', 'signed_by_name', 'sent_at',
    ];

    protected $attributes = ['status' => 'empty'];

    /** « À terminer » compte les lettres des sorties à partir de ce jour-là, pas celles d'avant la lettre */
    public const SINCE = '2026-11-09';

    /** Les rubriques écrites par l'IA (puis relues), dans l'ordre de la lettre */
    public const SECTIONS = ['stay', 'during', 'home', 'call'];

    public const SECTION_LABELS = [
        'stay' => 'Le séjour',
        'during' => 'Pendant le séjour',
        'home' => 'À la maison',
        'call' => 'Appelez-nous',
    ];

    protected function casts(): array
    {
        return [
            'home_notes' => 'encrypted',
            'draft' => 'encrypted:array',
            'sections' => 'encrypted:array',
            'copied' => 'encrypted:array',
            'edited_sections' => 'array',
            'edited_at' => 'array',
            'is_redraft_pending' => 'boolean',
            'written_at' => 'datetime',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'writings' => 'integer',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
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
