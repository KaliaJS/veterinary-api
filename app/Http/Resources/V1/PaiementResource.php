<?php

namespace App\Http\Resources\V1;

use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tentative de paiement Stripe d'une facture. `facture` accompagne un
 * paiement réussi : la caisse remplace sa facture (payée) sans autre
 * requête.
 *
 * @mixin Paiement
 */
class PaiementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'facture_id' => $this->facture_id,
            'moyen' => $this->moyen,
            'montant' => (float) $this->montant,
            'statut' => $this->statut,
            'erreur' => $this->erreur,
            'url_twint' => $this->url_twint,
            'terminal' => $this->terminal ? ['id' => $this->terminal->id, 'libelle' => $this->terminal->libelle] : null,
            'reussi_le' => $this->reussi_le,
            'facture' => new FactureResource($this->whenLoaded('facture')),
        ];
    }
}
