<?php

namespace App\Http\Resources\V1;

use App\Models\TerminalPaiement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Terminal de paiement de la clinique. `en_ligne` est lu chez Stripe au
 * moment de la requête (null : état inconnu).
 *
 * @mixin TerminalPaiement
 */
class TerminalPaiementResource extends JsonResource
{
    public function __construct(TerminalPaiement $resource, private ?bool $enLigne = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'libelle' => $this->libelle,
            'modele' => $this->modele,
            'numero_serie' => $this->numero_serie,
            'en_ligne' => $this->enLigne,
            'est_simule' => str_starts_with((string) $this->modele, 'simulated'),
        ];
    }
}
