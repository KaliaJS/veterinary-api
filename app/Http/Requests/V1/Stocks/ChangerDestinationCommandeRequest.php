<?php

namespace App\Http\Requests\V1\Stocks;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/** La clinique de livraison d'un brouillon, une clinique de l'organisation (28.10.2026). */
class ChangerDestinationCommandeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinique_id' => ['required', 'integer', $this->estUneCliniqueDeLOrganisation()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clinique_id.required' => 'Choisissez la clinique de livraison.',
            'clinique_id.integer' => 'Choisissez la clinique de livraison.',
            'clinique_id.exists' => 'Choisissez une clinique de votre organisation.',
        ];
    }
}
