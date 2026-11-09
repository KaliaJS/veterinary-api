<?php

namespace App\Models;

use App\Events\ReferralLetterUpdated;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La lettre de référence vers un confrère (choix de Jim, 09.11.2026) : les
 * pièces jointes (`items`), les notes du vétérinaire (motif et question),
 * le brouillon de l'IA (sources numérotées, phrases et sources), le texte
 * relu par rubrique, l'identité et le traitement en cours recopiés (figés à
 * la signature). Par clinique ; signée, figée et rangée au dossier.
 */
class ReferralLetter extends Model
{
    use AppartientAUneClinique;

    protected $table = 'referral_letters';

    protected $fillable = [
        'organisation_id', 'clinique_id', 'patient_id', 'status', 'error', 'items', 'include_visit_notes',
        'recipient_name', 'recipient_clinic', 'recipient_email', 'notes', 'draft', 'sections', 'edited_sections', 'edited_at',
        'copied', 'writings', 'is_redraft_pending', 'written_at', 'signed_at', 'signed_by_id', 'signed_by_name',
        'document_id', 'record_share_id', 'created_by_id',
    ];

    protected $attributes = ['status' => 'empty', 'items' => '[]'];

    /** Les rubriques écrites par l'IA (puis relues), dans l'ordre de la lettre */
    public const SECTIONS = ['reason', 'history', 'exams', 'request'];

    public const SECTION_LABELS = [
        'reason' => 'Motif de la référence',
        'history' => 'Anamnèse et examen',
        'exams' => 'Examens complémentaires',
        'request' => 'Notre demande',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'include_visit_notes' => 'boolean',
            'notes' => 'encrypted',
            'draft' => 'encrypted:array',
            'sections' => 'encrypted:array',
            'copied' => 'encrypted:array',
            'edited_sections' => 'array',
            'edited_at' => 'array',
            'is_redraft_pending' => 'boolean',
            'written_at' => 'datetime',
            'sources_changed_at' => 'datetime',
            'signed_at' => 'datetime',
            'writings' => 'integer',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<RecordShare, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(RecordShare::class, 'record_share_id')->withoutGlobalScopes();
    }

    /** Les autres postes de la clinique relisent la lettre (Reverb), après la transaction, jamais au prix du geste */
    public static function announce(int $id, int $cliniqueId): void
    {
        TempsReel::diffuser(function () use ($id, $cliniqueId): void {
            broadcast(new ReferralLetterUpdated($id, $cliniqueId))->toOthers();
        });
    }

    /** Les pièces, les notes ou une dictée ont changé après la lecture des sources de ce brouillon */
    public function isDraftStale(): bool
    {
        if ($this->draft === null || $this->isSigned() || $this->status === 'writing' || $this->sources_changed_at === null) {
            return false;
        }
        $readAt = isset($this->draft['read_at']) ? CarbonImmutable::parse((string) $this->draft['read_at']) : $this->written_at;

        return $readAt === null || $this->sources_changed_at->gt($readAt);
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
