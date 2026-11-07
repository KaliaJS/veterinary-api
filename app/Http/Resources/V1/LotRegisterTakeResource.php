<?php

namespace App\Http\Resources\V1;

use App\Models\MouvementStupefiant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une sortie d'un lot de stupéfiant, lue dans son registre (07.11.2026) :
 * la même forme que LotTakeResource, la quantité en ml nette de ses
 * corrections, les noms figés à l'inscription.
 *
 * @mixin MouvementStupefiant
 */
class LotRegisterTakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $libelle = $this->type->libelle();

        return [
            'id' => $this->id,
            'numero_lot' => $this->numero_lot,
            'quantite' => round(-((float) $this->quantite + (float) $this->getAttribute('corrige')), 3),
            'libelle' => $this->motif !== $libelle ? $libelle.', '.$this->motif : $libelle,
            'source_type' => $this->getMorphClass(),
            'source_id' => $this->id,
            'patient' => $this->patient_id === null ? null : ['id' => $this->patient_id, 'nom' => $this->patient_nom],
            'client' => $this->patient?->client_id === null ? null : ['id' => $this->patient->client_id, 'nom' => $this->detenteur_nom],
            'created_at' => $this->created_at,
        ];
    }
}
