<?php

namespace App\Models;

use App\Enums\TypeSalle;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\SalleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Salle extends Model
{
    /** @use HasFactory<SalleFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $fillable = ['clinique_id', 'nom', 'type'];

    protected function casts(): array
    {
        return [
            'type' => TypeSalle::class,
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** Ligne du planning portée par cette salle-ressource (bloc, imagerie).
     * @return HasOne<LignePlanning, $this> */
    public function lignePlanning(): HasOne
    {
        return $this->hasOne(LignePlanning::class);
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }
}
