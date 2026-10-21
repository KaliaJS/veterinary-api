<?php

namespace App\Models;

use App\Enums\Specialite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une spécialité d'un vétérinaire, sur sa fiche de l'organisation. */
class EmployeSpecialite extends Model
{
    protected $table = 'employe_specialites';

    protected $fillable = ['specialite'];

    protected function casts(): array
    {
        return ['specialite' => Specialite::class];
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
