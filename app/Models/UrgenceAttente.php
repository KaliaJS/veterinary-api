<?php

namespace App\Models;

use App\Enums\Espece;
use App\Models\Concerns\AppartientAUneClinique;
use App\Models\Concerns\ChangeLesFilesDuPlanning;
use Database\Factories\UrgenceAttenteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrgenceAttente extends Model
{
    /** @use HasFactory<UrgenceAttenteFactory> */
    use AppartientAUneClinique, ChangeLesFilesDuPlanning, HasFactory;

    protected $table = 'urgences_attente';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'patient_name',
        'species', 'owner_name', 'reason', 'triage', 'duree_minutes', 'arrivee_le',
    ];

    protected function casts(): array
    {
        return [
            'species' => Espece::class,
            'triage' => 'integer',
            'duree_minutes' => 'integer',
            'arrivee_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
