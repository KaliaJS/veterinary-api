<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schéma d'emploi d'un vaccin (curé depuis l'information professionnelle) :
 * âge minimal, primo-vaccination (nombre d'injections, intervalle), premier
 * rappel, rappel courant, voie. Les champs vides signifient « selon
 * l'information professionnelle » ; la remarque précise.
 */
class VaccinSchema extends Model
{
    protected $table = 'vaccin_schemas';

    protected $fillable = [
        'produit_id', 'age_min_semaines', 'primo_injections', 'primo_intervalle_semaines',
        'rappel_premier_mois', 'rappel_mois', 'voie', 'remarques', 'source',
    ];

    /** @return BelongsTo<ProduitVeterinaire, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitVeterinaire::class, 'produit_id');
    }
}
