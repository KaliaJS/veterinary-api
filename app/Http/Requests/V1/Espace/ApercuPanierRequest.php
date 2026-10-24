<?php

namespace App\Http\Requests\V1\Espace;

use Illuminate\Foundation\Http\FormRequest;

/** Le panier à chiffrer, comme à la commande : par clinique de retrait, lignes en quantités. */
class ApercuPanierRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'commandes' => ['required', 'array', 'min:1', 'max:20'],
            'commandes.*.clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            'commandes.*.lignes' => ['required', 'array', 'min:1', 'max:50'],
            'commandes.*.lignes.*.produit_id' => ['required', 'integer', 'exists:produits,id'],
            'commandes.*.lignes.*.quantite' => ['required', 'integer', 'between:1,99'],
        ];
    }
}
