<?php

namespace App\Models;

use Database\Factories\PeseeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pesee extends Model
{
    /** @use HasFactory<PeseeFactory> */
    use HasFactory;

    protected $fillable = ['patient_id', 'poids_kg', 'pesee_le'];

    protected function casts(): array
    {
        return [
            'poids_kg' => 'decimal:3',
            'pesee_le' => 'date',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
