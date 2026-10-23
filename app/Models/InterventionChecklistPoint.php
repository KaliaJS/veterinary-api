<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point de la checklist de sécurité d'une intervention. Enfant du
 * dossier : pas de scope propre, toujours résolu via l'intervention
 * (par son `code`). Seuls les points ajoutés (`est_ajoute`) se
 * retirent ; `consent` ne se coche que par la signature.
 */
class InterventionChecklistPoint extends Model
{
    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'est_coche' => false,
        'est_ajoute' => false,
    ];

    protected $fillable = ['intervention_id', 'code', 'libelle', 'est_coche', 'est_ajoute'];

    protected function casts(): array
    {
        return [
            'est_coche' => 'boolean',
            'est_ajoute' => 'boolean',
        ];
    }

    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }
}
