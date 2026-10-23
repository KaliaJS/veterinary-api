<?php

namespace App\Http\Requests\V1\Hospitalisation;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Soin planifié à la volée sur le plan de soins : heure locale du jour
 * en minutes, libellé, produit du catalogue de l'organisation en
 * option (son stock sortira au « soin fait »).
 */
class PlanifierSoinRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Jour local de la clinique, aujourd'hui par défaut
            'jour' => ['sometimes', 'date_format:Y-m-d'],
            'prevu_a' => ['required', 'integer', 'between:0,1439'],
            'acte' => ['required', 'string', 'max:255'],
            'produit_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('produits')],
        ];
    }
}
