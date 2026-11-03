<?php

namespace App\Models;

use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\PeseeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pesee extends Model
{
    /** @use HasFactory<PeseeFactory> */
    use HasFactory, RecordsSourceOrganisation;

    protected $fillable = ['patient_id', 'poids_kg', 'pesee_le', 'source_organisation_id'];

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
