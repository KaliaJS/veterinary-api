<?php

namespace App\Http\Requests\V1\Stocks;

use App\Enums\CategorieProduit;
use App\Http\Requests\V1\Stocks\Concerns\ChampPeremption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerProduitRequest extends FormRequest
{
    use ChampPeremption;

    public function peremptionValidee(): ?CarbonImmutable
    {
        return $this->peremptionLe($this->validated('peremption'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'categorie' => ['required', Rule::enum(CategorieProduit::class)],
            'prix' => ['required', 'numeric', 'min:0', 'max:99999'],
            'cout' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'unite' => ['sometimes', 'string', 'max:30'],
            'seuil_alerte' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'est_boutique' => ['sometimes', 'boolean'],
            'est_sur_ordonnance' => ['sometimes', 'boolean'],
            'peremption' => $this->reglesPeremption(),
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'ean' => ['nullable', 'string', 'max:20'],
            'quantite_initiale' => ['sometimes', 'integer', 'min:0', 'max:99999'],
        ];
    }
}
