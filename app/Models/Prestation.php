<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\PrestationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prestation du catalogue d'actes — UNIQUE par organisation (décision
 * ouverte de docs/data-model.md tranchée : un acte, un prix). Les huit
 * actes liés à un type de RDV portent le code du type ; le passage d'un
 * RDV en « fini » facture la prestation correspondante.
 */
class Prestation extends Model
{
    /** @use HasFactory<PrestationFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = ['organisation_id', 'code', 'nom', 'prix'];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
