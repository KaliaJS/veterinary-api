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
            // La fiche client de la vente, de l'organisation (28.10.2026)
            'client_id' => ['nullable', 'integer', $this->existeDansLOrganisation('clients')],
            'moyen_paiement' => ['required', Rule::enum(MoyenPaiement::class)],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', $this->existeDansLOrganisation('produits')],
            'lignes.*.quantite' => ['required', 'integer', 'between:1,99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_nom.string' => 'Le nom du client est trop long ou mal formé.',
            'client_nom.max' => 'Le nom du client est trop long (255 caractères au plus).',
            'client_id.integer' => 'Choisissez une fiche client de votre organisation.',
            'client_id.exists' => 'Choisissez une fiche client de votre organisation.',
            'moyen_paiement.required' => 'Choisissez le moyen de paiement.',
            'moyen_paiement.enum' => 'Choisissez le moyen de paiement.',
            'lignes.required' => 'Ajoutez au moins un article au ticket.',
            'lignes.min' => 'Ajoutez au moins un article au ticket.',
            'lignes.array' => 'Ajoutez au moins un article au ticket.',
            'lignes.*.produit_id.required' => 'Un article du ticket est introuvable.',
            'lignes.*.produit_id.exists' => 'Un article du ticket est introuvable.',
            'lignes.*.quantite.required' => 'Une ligne compte de 1 à 99 articles.',
            'lignes.*.quantite.integer' => 'Une ligne compte de 1 à 99 articles.',
            'lignes.*.quantite.between' => 'Une ligne compte de 1 à 99 articles.',
        ];
    }
}
