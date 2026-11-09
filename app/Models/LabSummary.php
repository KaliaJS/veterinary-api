<?php

namespace App\Models;

use App\Events\LabSummaryUpdated;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;

/**
 * Le message au propriétaire sur une analyse validée, rédigé par l'IA
 * (09.11.2026) : en mots simples, relu par le vétérinaire, signé, envoyé
 * dans sa messagerie. Par clinique.
 */
class LabSummary extends Model
{
    use AppartientAUneClinique;

    protected $table = 'lab_summaries';

    protected $fillable = [
        'organisation_id', 'clinique_id', 'analyse_id', 'status', 'error', 'draft', 'sections', 'edited_sections',
        'writings', 'written_at', 'signed_at', 'signed_by_id', 'signed_by_name', 'sent_at',
    ];

    protected $attributes = ['status' => 'empty'];

    /** Le texte pour le propriétaire seulement (décision de Jim, 09.11.2026 : sans interprétation, une partie pour le vétérinaire n'apporte rien) */
    public const SECTIONS = ['owner'];

    public const SECTION_LABELS = [
        'owner' => 'Pour le propriétaire',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'encrypted:array',
            'sections' => 'encrypted:array',
            'edited_sections' => 'array',
            'writings' => 'integer',
            'written_at' => 'datetime',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    /**
     * @param  array<string, mixed>|null  $sections
     * @return array<string, string>
     */
    public static function normalizeSections(?array $sections): array
    {
        return collect(self::SECTIONS)->mapWithKeys(fn (string $k) => [$k => trim((string) ($sections[$k] ?? ''))])->all();
    }

    /** Les autres postes relisent le résumé (Reverb), après la transaction */
    public static function announce(int $analyseId, int $cliniqueId): void
    {
        TempsReel::diffuser(function () use ($analyseId, $cliniqueId): void {
            broadcast(new LabSummaryUpdated($analyseId, $cliniqueId))->toOthers();
        });
    }
}
