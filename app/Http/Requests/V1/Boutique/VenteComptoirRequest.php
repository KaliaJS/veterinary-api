<?php

namespace App\Http\Requests\V1\Boutique;

use App\Enums\MoyenPaiement;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vente comptoir : panier borné au stock de la clinique du jeton
 * (contrôlé au contrôleur), encaissée immédiatement.
 */
class VenteComptoirRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_nom' => ['nullable', 'string', 'max:255'],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', $this->existeDansLOrganisation('produits')],
            'lignes.*.quantite' => ['required', 'integer', 'between:1,99'],
        ];
    }
}
