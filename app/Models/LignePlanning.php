<?php

namespace App\Models;

use App\Enums\RoleLignePlanning;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\LignePlanningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Colonne du planning : un praticien (employe_id) ou une ressource
 * (salle_id — bloc, imagerie), jamais les deux.
 */
class LignePlanning extends Model
{
    /** @use HasFactory<LignePlanningFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'lignes_planning';

    protected $fillable = [
        'clinique_id', 'employe_id', 'salle_id', 'nom', 'sous_titre',
        'role', 'initiales', 'groupe', 'ordre',
    ];

    protected function casts(): array
    {
        return [
            'role' => RoleLignePlanning::class,
            'ordre' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    /** @return BelongsTo<Salle, $this> */
    public function salle(): BelongsTo
    {
        return $this->belongsTo(Salle::class);
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class, 'ligne_planning_id');
    }
}
