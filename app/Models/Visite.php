<?php

namespace App\Models;

use Database\Factories\VisiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visite extends Model
{
    /** @use HasFactory<VisiteFactory> */
    use HasFactory;

    protected $fillable = ['patient_id', 'visite_le', 'motif', 'employe_id', 'note'];

    protected function casts(): array
    {
        return [
            'visite_le' => 'date',
        ];
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
}
