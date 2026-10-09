<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Événement webhook Stripe déjà traité : Stripe peut livrer deux fois
 * le même événement, l'id sert de verrou.
 */
class EvenementStripe extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'evenements_stripe';

    protected $keyType = 'string';

    protected $fillable = ['id', 'type', 'traite_le'];

    protected function casts(): array
    {
        return ['traite_le' => 'datetime'];
    }
}
