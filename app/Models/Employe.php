<?php

namespace App\Models;

use Database\Factories\EmployeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employe extends Model
{
    /** @use HasFactory<EmployeFactory> */
    use HasFactory;

    protected $fillable = [
        'clinique_id', 'user_id', 'nom', 'initiales', 'role', 'taux',
        'contrat', 'entree_le', 'tel', 'email', 'conges_droit', 'conges_pris',
    ];

    protected function casts(): array
    {
        return [
            'entree_le' => 'date',
            'taux' => 'integer',
            'conges_droit' => 'integer',
            'conges_pris' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Colonne du planning — seuls les vétérinaires en ont une.
     * @return HasOne<LignePlanning, $this> */
    public function lignePlanning(): HasOne
    {
        return $this->hasOne(LignePlanning::class);
    }

    /** @return HasMany<Absence, $this> */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }
}
