<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un compte de paiement de vendeur effacé, à fermer chez le prestataire (31.10.2026). */
class SellerAccountClosure extends Model
{
    protected $fillable = ['stripe_account_id', 'requested_at', 'closed_at', 'attempts', 'last_error', 'reported_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'closed_at' => 'datetime', 'reported_at' => 'datetime', 'attempts' => 'integer'];
    }
}
