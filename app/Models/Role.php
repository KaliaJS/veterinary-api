<?php

namespace App\Models;

use App\Enums\DomainePermission;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Rôle de l'organisation : les droits qu'il accorde (écran Rôles et droits).
 *
 * @property Collection<int, DomainePermission> $domaines
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = ['organisation_id', 'nom', 'domaines'];

    protected function casts(): array
    {
        return [
            'domaines' => AsEnumCollection::of(DomainePermission::class),
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<Employe, $this> */
    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class);
    }
}
