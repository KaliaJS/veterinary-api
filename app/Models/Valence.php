<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Maladie couverte par un vaccin (« Rage », « Leptospirose »…), en français. */
class Valence extends Model
{
    protected $fillable = ['code', 'nom', 'nom_court', 'agent'];

    /** @return BelongsToMany<ProduitVeterinaire, $this> */
    public function produits(): BelongsToMany
    {
        return $this->belongsToMany(ProduitVeterinaire::class, 'produit_valence', 'valence_id', 'produit_id');
    }
}
