<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\PeseeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pesee extends Model
{
    /** @use HasFactory<PeseeFactory> */
    use HasFactory, RecordsAuthor, RecordsSourceOrganisation;

    protected $fillable = ['patient_id', 'employe_id', 'poids_kg', 'pesee_le', 'source_organisation_id'];

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

    /** Qui a pesé l'animal (06.11.2026) ; aucun : le propriétaire, ou une pesée plus ancienne
     * @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class)->withoutGlobalScope('clinique');
    }
}
