<?php

namespace App\Models;

use Database\Factories\VaccinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vaccination extends Model
{
    /** @use HasFactory<VaccinationFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id', 'nom', 'marque', 'lot', 'employe_id', 'injectee_le', 'echeance_le',
    ];

    protected function casts(): array
    {
        return [
            'injectee_le' => 'date',
            'echeance_le' => 'date',
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
