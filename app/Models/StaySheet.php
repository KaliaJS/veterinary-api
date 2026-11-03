<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La feuille d'un animal pour un jour, de 06:00 à 06:00 (03.11.2026), et
 * le rapport de la journée rédigé par l'IA. Enfant du dossier : résolue
 * via le séjour.
 */
class StaySheet extends Model
{
    public const string REPORT_NONE = 'none';

    public const string REPORT_DRAFTING = 'drafting';

    public const string REPORT_TO_REVIEW = 'to_review';

    public const string REPORT_SIGNED = 'signed';

    public const string REPORT_FAILED = 'failed';

    protected $table = 'stay_sheets';

    protected $attributes = [
        'report_status' => self::REPORT_NONE,
        'report_drafts' => 0,
    ];

    protected $fillable = [
        'sejour_id', 'day', 'opened_at', 'report_status', 'report_text', 'report_source', 'report_error', 'report_drafts',
        'report_drafted_at', 'report_signed_at', 'report_signed_by_id', 'report_signed_by_name', 'report_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'opened_at' => 'datetime',
            'report_drafts' => 'integer',
            'report_drafted_at' => 'datetime',
            'report_signed_at' => 'datetime',
            'report_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }
}
