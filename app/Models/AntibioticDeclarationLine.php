<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une préparation d'une déclaration SI ABV ; sans scope propre, elle se lit par sa déclaration. */
class AntibioticDeclarationLine extends Model
{
    protected $fillable = [
        'declaration_id', 'produit_id', 'ordonnance_ligne_id', 'preparation_code', 'route_code',
        'dose_1', 'frequency_1', 'days_1', 'dose_2', 'frequency_2', 'days_2',
        'total_quantity', 'is_total_entered', 'given_quantity', 'is_given_entered', 'starts_on', 'ends_on', 'is_critical_confirmed',
    ];

    protected function casts(): array
    {
        return [
            'dose_1' => 'float',
            'dose_2' => 'float',
            'days_1' => 'integer',
            'days_2' => 'integer',
            'total_quantity' => 'float',
            'given_quantity' => 'float',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_critical_confirmed' => 'boolean',
            'is_total_entered' => 'boolean',
            'is_given_entered' => 'boolean',
        ];
    }

    /** @return BelongsTo<AntibioticDeclaration, $this> */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(AntibioticDeclaration::class, 'declaration_id');
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<IsabvPreparation, $this> */
    public function preparation(): BelongsTo
    {
        return $this->belongsTo(IsabvPreparation::class, 'preparation_code', 'code');
    }
}
