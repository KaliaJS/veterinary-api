<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Achat de l'App Store remboursé par Apple : jamais rendu par une ancienne transaction. */
class RevocationAppStore extends Model
{
    protected $table = 'revocations_app_store';

    protected $fillable = ['transaction_id', 'produit', 'user_id', 'revoquee_le'];

    protected function casts(): array
    {
        return ['revoquee_le' => 'datetime'];
    }
}
