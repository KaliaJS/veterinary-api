<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Events\GardesModifiees;
use App\Support\TempsReel;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory;

    /**
     * Pas de `clinique_id` propre : l'isolation passe par l'employé,
     * dont le scope global porte la clinique du contexte. Indispensable
     * au route model binding — sans lui, une absence d'une autre
     * clinique serait résolue.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('clinique', function (Builder $builder): void {
            if (app(ContexteClinique::class)->estDefini()) {
                $builder->whereHas('employe');
            }
        });
        // Une absence fait passer son tour à un vétérinaire : les nuits de garde de ses cliniques changent (11.11.2026)
        $prevenir = function (Absence $absence): void {
            foreach (DB::table('clinique_employe')->where('employe_id', $absence->employe_id)->pluck('clinique_id') as $cliniqueId) {
                TempsReel::diffuser(fn () => GardesModifiees::dispatch((int) $cliniqueId));
            }
        };
        static::saved($prevenir);
        static::deleted($prevenir);
    }

    protected $fillable = [
        'employe_id', 'type', 'debut_le', 'fin_le', 'statut', 'note', 'valide_par_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeAbsence::class,
            'statut' => StatutAbsence::class,
            'debut_le' => 'date',
            'fin_le' => 'date',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_user_id');
    }

    /**
     * Une absence validée hors garde couvre la colonne du praticien et
     * interdit la création de RDV ; la garde n'est qu'un badge.
     */
    public function estBloquante(): bool
    {
        return $this->statut === StatutAbsence::Validee && $this->type !== TypeAbsence::Garde;
    }
}
