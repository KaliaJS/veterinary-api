<?php

namespace App\Models;

use App\Events\GardesModifiees;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Database\Factories\GardeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le vétérinaire d'une nuit de garde : choisi par un manager
 * (`est_choisie`, `choisie_par_user_id`), ou la suggestion telle qu'elle
 * s'est montrée. Une nuit du registre (`enregistree_le` : le passage du
 * matin, ou une correction, É26 à É28) ne change plus. Une nuit à venir
 * sans choix reste DÉRIVÉE (RotationGardes).
 */
class Garde extends Model
{
    /** @use HasFactory<GardeFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $fillable = ['clinique_id', 'jour', 'employe_id', 'est_choisie', 'enregistree_le', 'choisie_par_user_id'];

    /** Un choix prévient le Planning et l'agenda en temps réel (les nuits figées s'écrivent sans événement) */
    protected static function booted(): void
    {
        $prevenir = fn (Garde $garde) => TempsReel::diffuser(fn () => GardesModifiees::dispatch((int) $garde->clinique_id));
        static::saved($prevenir);
        static::deleted($prevenir);
    }

    protected function casts(): array
    {
        return [
            'jour' => 'date',
            'est_choisie' => 'boolean',
            'enregistree_le' => 'datetime',
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
}
