<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traitement en cours d'un séjour (03.11.2026) : ses heures de prise
 * donnent les administrations de chaque feuille du jour. Enfant du
 * dossier : pas de scope propre, toujours résolu via le séjour.
 */
class StayTreatment extends Model
{
    protected $table = 'stay_treatments';

    protected $fillable = [
        'sejour_id', 'label', 'product_id', 'dose', 'dose_amount', 'times', 'starts_at', 'ends_at',
        'created_by_id', 'created_by_name', 'stopped_by_name',
    ];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'dose_amount' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sejour, $this> */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'product_id');
    }

    /** @return list<int> */
    public function minutes(): array
    {
        $minutes = array_map('intval', $this->times ?? []);
        sort($minutes);

        return array_values(array_unique($minutes));
    }
}
