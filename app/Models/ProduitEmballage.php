<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Emballage autorisé d'un produit, et son GTIN (code-barres) quand Refdata le connaît. */
class ProduitEmballage extends Model
{
    protected $table = 'produit_emballages';

    protected $fillable = [
        'produit_id', 'numero_emballage', 'sequence', 'taille', 'unite', 'remarque', 'statut', 'retire_le',
        'gtin', 'nom_fr', 'commercialise_le', 'arrete_le',
    ];

    protected function casts(): array
    {
        return [
            'retire_le' => 'date',
            'commercialise_le' => 'date',
            'arrete_le' => 'date',
        ];
    }

    /** @return BelongsTo<ProduitVeterinaire, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitVeterinaire::class, 'produit_id');
    }
}
