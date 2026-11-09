<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;

/**
 * Une entrée en stock, une restitution ou une destruction de médicaments
 * d'une exploitation (OMédV art. 28 al. 2, 09.11.2026) : la date, la
 * dénomination commerciale, la quantité en unités de conditionnement, le
 * fournisseur ou qui reprend ; pour une remise à titre de stocks, son
 * usage et sa durée (art. 11 al. 2).
 */
class FarmStockMovement extends Model
{
    use AppartientAUneOrganisation;

    public const array KINDS = ['entry', 'return', 'destruction'];

    protected $fillable = ['organisation_id', 'client_id', 'kind', 'moved_on', 'product_name', 'quantity', 'counterpart', 'produit_id', 'purpose', 'months', 'medvet_agreement_id', 'facture_id', 'recorded_by_id'];

    protected function casts(): array
    {
        return ['moved_on' => 'date:Y-m-d', 'months' => 'integer'];
    }
}
