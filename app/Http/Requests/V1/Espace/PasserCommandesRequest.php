<?php

namespace App\Http\Requests\V1\Espace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Panier de l'espace client : une commande par clinique de retrait
 * (chaque clinique vend son propre stock), lignes en quantités.
 */
class PasserCommandesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['nullable', 'string', 'max:255'],
            'commandes' => ['required', 'array', 'min:1'],
            'commandes.*.clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            'commandes.*.lignes' => ['required', 'array', 'min:1'],
            'commandes.*.lignes.*.produit_id' => ['required', 'integer', 'exists:produits,id'],
            'commandes.*.lignes.*.quantite' => ['required', 'integer', 'between:1,99'],
        ];
    }
}
