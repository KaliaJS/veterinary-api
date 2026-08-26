<?php

namespace App\Models;

use App\Enums\DomainePermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajustement par employé au-dessus du rôle : accorde ou retire un
 * domaine de permission.
 */
class DerogationPermission extends Model
{
    protected $table = 'derogations_permission';

    protected $fillable = ['employe_id', 'domaine', 'est_accordee'];

    protected function casts(): array
    {
        return [
            'domaine' => DomainePermission::class,
            'est_accordee' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
