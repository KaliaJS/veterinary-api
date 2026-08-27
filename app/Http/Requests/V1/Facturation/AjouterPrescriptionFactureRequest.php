<?php

namespace App\Http\Requests\V1\Facturation;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Prescription « à retirer en pharmacie » jointe à la facture : aucune
 * ligne facturée, aucun stock — le chemin de la rupture de stock.
 */
class AjouterPrescriptionFactureRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'produit_id' => ['required', $this->existeDansLOrganisation('produits')],
            'posologie' => ['nullable', 'string', 'max:255'],
        ];
    }
}
