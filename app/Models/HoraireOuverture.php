<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\HoraireOuvertureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoraireOuverture extends Model
{
    /** @use HasFactory<HoraireOuvertureFactory> */
    use AppartientAUneClinique, HasFactory;

    /** Valeurs par défaut du front (docs/data-model.md §3 — Horaires). */
    public const array DEFAUTS = [
        'ouverture' => '08:00',
        'fermeture' => '19:30',
        'fermeture_samedi' => '15:00',
        'samedi_ferme' => false,
        'dimanche_ferme' => true,
        'pause_active' => true,
        'pause_debut' => '12:30',
        'pause_fin' => '14:00',
    ];

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
