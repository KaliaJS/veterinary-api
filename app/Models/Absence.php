<?php

namespace App\Models;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory;

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
