<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La part d'un vendeur dans la cagnotte d'un mois, versée à 10 CHF ou en fin d'année. */
class PlusPoolShare extends Model
{
    protected $fillable = ['plus_pool_month_id', 'seller_user_id', 'seller_clinique_id', 'views', 'earned_cents', 'stripe_account_id', 'transfer_id', 'payout_group', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /** @return BelongsTo<PlusPoolMonth, $this> */
    public function month(): BelongsTo
    {
        return $this->belongsTo(PlusPoolMonth::class, 'plus_pool_month_id');
    }
}
