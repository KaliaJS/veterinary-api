<?php

namespace App\Http\Requests\V1\Stocks;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Rules\CliniqueOuverte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Préparer les brouillons du stock bas (03.11.2026) : sans `lignes`, tout
 * ce qui est sous le seuil ; avec `lignes`, seulement les articles cochés,
 * aux quantités corrigées. Un article de l'organisation seulement, une
 * fois chacun.
 */
class GenerateSupplierOrdersRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Toujours la clinique du jeton : on commande depuis la clinique
            'clinique_id' => ['sometimes', 'nullable', 'integer', new CliniqueOuverte],
            'lignes' => ['sometimes', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'distinct', $this->existeDansLOrganisation('produits')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lignes.min' => 'Cochez au moins un article.',
            'lignes.*.produit_id.distinct' => 'Un article ne se commande qu’une fois.',
        ];
    }
}
