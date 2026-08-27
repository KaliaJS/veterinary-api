<?php

namespace App\Http\Requests\V1\Boutique;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Commande en ligne : un client (fiche de l'organisation ou nom libre)
 * et des articles visibles en boutique. Le retrait se fait dans la
 * clinique du jeton.
 */
class EnregistrerCommandeBoutiqueRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', $this->existeDansLOrganisation('clients')],
            'client_nom' => ['required_without:client_id', 'string', 'max:255'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => [
                'required',
                $this->existeDansLOrganisation('produits')->where('est_boutique', true),
            ],
            'lignes.*.quantite' => ['required', 'integer', 'between:1,99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lignes.*.produit_id.exists' => 'Seul un article visible en boutique se commande en ligne.',
        ];
    }
}
