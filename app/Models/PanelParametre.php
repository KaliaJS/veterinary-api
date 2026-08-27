<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paramètre d'un panel du référentiel — enfant sans scope propre,
 * résolu via son panel (scopé organisation).
 */
class PanelParametre extends Model
{
    protected $fillable = ['panel_labo_id', 'position', 'nom', 'unite', 'borne_basse', 'borne_haute'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'borne_basse' => 'decimal:2',
            'borne_haute' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PanelLabo, $this> */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(PanelLabo::class, 'panel_labo_id');
    }
}
