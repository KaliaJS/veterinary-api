<?php

namespace App\Models;

use App\Enums\DrapeauResultatLabo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Résultat d'un paramètre d'une analyse — bornes instantanées du
 * référentiel, valeur saisie pendant l'analyse, drapeau H/L dérivé.
 * Enfant sans scope propre, résolu via l'analyse.
 */
class AnalyseResultat extends Model
{
    protected $fillable = [
        'analyse_id', 'analyse_panel_id', 'position', 'parametre',
        'unite', 'borne_basse', 'borne_haute', 'valeur', 'drapeau',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'borne_basse' => 'decimal:2',
            'borne_haute' => 'decimal:2',
            'valeur' => 'decimal:2',
            'drapeau' => DrapeauResultatLabo::class,
        ];
    }

    /** @return BelongsTo<Analyse, $this> */
    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class);
    }

    /** @return BelongsTo<AnalysePanel, $this> */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(AnalysePanel::class, 'analyse_panel_id');
    }
}
