<?php

namespace App\Http\Requests\V1\Boutique;

/**
 * Aperçu du ticket comptoir (28.10.2026) : le panier seul, sans moyen de
 * paiement ni client, pour chiffrer avant l'encaissement.
 */
class ApercuVenteComptoirRequest extends VenteComptoirRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(parent::rules())->only(['lignes', 'lignes.*.produit_id', 'lignes.*.quantite'])->all();
    }
}
