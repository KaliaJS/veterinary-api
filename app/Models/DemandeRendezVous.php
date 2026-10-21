<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\DemandeRendezVousFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeRendezVous extends Model
{
    /** @use HasFactory<DemandeRendezVousFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'demandes_rendez_vous';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'patient_name',
        'species', 'owner_name', 'email', 'telephone', 'reason', 'urgence',
        'symptomes', 'souhait', 'mode', 'duree_minutes',
    ];

    protected function casts(): array
    {
        return [
            'species' => Espece::class,
            'mode' => ModeDemandeRendezVous::class,
            'urgence' => 'boolean',
            'duree_minutes' => 'integer',
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
