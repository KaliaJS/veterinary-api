<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un complément daté et signé, ajouté à un rapport de bloc validé (09.11.2026). */
class SurgeryReportComplement extends Model
{
    protected $table = 'surgery_report_complements';

    protected $fillable = ['surgery_report_id', 'text', 'by_id', 'by_name'];

    protected function casts(): array
    {
        return ['text' => 'encrypted'];
    }

    /** @return BelongsTo<SurgeryReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SurgeryReport::class, 'surgery_report_id');
    }
}
