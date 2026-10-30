<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La cagnotte Plus d'un mois (31.10.2026) : la moitié des revenus nets de Plus, partagée selon les vues. */
class PlusPoolMonth extends Model
{
    protected $fillable = ['month', 'net_revenue_cents', 'pool_cents', 'views', 'computed_at'];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }

    /** @return HasMany<PlusPoolShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(PlusPoolShare::class);
    }
}
