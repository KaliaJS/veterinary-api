<?php

namespace App\Http\Requests\V1\Stocks;

use App\Enums\CategorieProduit;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Http\Requests\V1\Stocks\Concerns\ChampPeremption;
use App\Support\AtcCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerProduitRequest extends FormRequest
{
    use ChampPeremption;
    use ReglesScopees;

    /** Le code ATC s'écrit sans espace, en capitales (« qm01ac06 » devient « QM01AC06 ») */
    protected function prepareForValidation(): void
    {
        if ($this->has('atc_code')) {
            $this->merge(['atc_code' => AtcCode::normalize($this->input('atc_code'))]);
        }
    }

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
            // Code ATC ou ATCvet (03.11.2026) : la famille du médicament, pour la mise en garde des allergies
            'atc_code' => ['nullable', 'string', 'max:12', 'regex:'.AtcCode::PATTERN],
            'quantite_initiale' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            // Clinique qui reçoit le stock initial (celle du jeton sinon).
            'clinique_id' => ['sometimes', 'nullable', 'integer', $this->estUneCliniqueDeLOrganisation()],
        ];
    }

    public function cliniqueId(): ?int
    {
        return $this->filled('clinique_id') ? (int) $this->validated('clinique_id') : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return AtcCode::messages();
    }
}
