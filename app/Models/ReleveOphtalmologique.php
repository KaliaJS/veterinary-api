<?php

namespace App\Models;

use App\Enums\ResultatFluoresceine;
use Database\Factories\ReleveOphtalmologiqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleveOphtalmologique extends Model
{
    /** @use HasFactory<ReleveOphtalmologiqueFactory> */
    use HasFactory;

    protected $table = 'releves_ophtalmologiques';

    protected $fillable = [
        'patient_id', 'releve_le', 'schirmer_od', 'schirmer_og',
        'pio_od', 'pio_og', 'fluoresceine', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'releve_le' => 'date',
            'fluoresceine' => ResultatFluoresceine::class,
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
