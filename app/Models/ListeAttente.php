<?php

namespace App\Models;

use App\Enums\Espece;
use Database\Factories\ListeAttenteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListeAttente extends Model
{
    /** @use HasFactory<ListeAttenteFactory> */
    use HasFactory;

    protected $table = 'liste_attente';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'patient_name',
        'species', 'owner_name', 'tel', 'reason', 'preference',
        'ligne_planning_id', 'duree_minutes', 'inscrit_le',
    ];

    protected function casts(): array
    {
        return [
            'species' => Espece::class,
            'duree_minutes' => 'integer',
            'inscrit_le' => 'datetime',
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

    /** Vétérinaire souhaité — null = n'importe lequel.
     * @return BelongsTo<LignePlanning, $this> */
    public function lignePlanning(): BelongsTo
    {
        return $this->belongsTo(LignePlanning::class, 'ligne_planning_id');
    }
}
