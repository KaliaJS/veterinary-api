<?php

namespace App\Models;

use Database\Factories\HoraireOuvertureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoraireOuverture extends Model
{
    /** @use HasFactory<HoraireOuvertureFactory> */
    use HasFactory;

    protected $table = 'horaires_ouverture';

    protected $fillable = [
        'clinique_id', 'ouverture', 'fermeture', 'fermeture_samedi',
        'samedi_ferme', 'dimanche_ferme', 'pause_active', 'pause_debut', 'pause_fin',
    ];

    protected function casts(): array
    {
        return [
            'samedi_ferme' => 'boolean',
            'dimanche_ferme' => 'boolean',
            'pause_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
