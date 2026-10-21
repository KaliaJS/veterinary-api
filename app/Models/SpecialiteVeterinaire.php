<?php

namespace App\Models;

use App\Enums\Specialite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une spécialité d'un vétérinaire, sur son compte (il la déclare lui-même). */
class SpecialiteVeterinaire extends Model
{
    protected $table = 'specialites_veterinaire';

    protected $fillable = ['specialite'];

    protected function casts(): array
    {
        return ['specialite' => Specialite::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
