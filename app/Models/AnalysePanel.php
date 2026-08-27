<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel demandé d'une analyse — instantané (code, nom, tube) du
 * référentiel au moment de la demande. Enfant sans scope propre,
 * résolu via l'analyse.
 */
class AnalysePanel extends Model
{
    protected $fillable = ['analyse_id', 'panel_labo_id', 'code', 'nom', 'tube'];

    /** @return BelongsTo<Analyse, $this> */
    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class);
    }

    /** @return BelongsTo<PanelLabo, $this> */
    public function panelLabo(): BelongsTo
    {
        return $this->belongsTo(PanelLabo::class);
    }

    /** @return HasMany<AnalyseResultat, $this> */
    public function resultats(): HasMany
    {
        return $this->hasMany(AnalyseResultat::class)->orderBy('position');
    }
}
