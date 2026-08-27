<?php

namespace App\Http\Requests\V1\Stupefiants;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mouvement du registre des stupéfiants : quantité signée dans l'unité
 * du produit (+ réception / − sortie), patient obligatoire pour une
 * sortie (traçabilité art. 62 OCStup), produit marqué stupéfiant. Le
 * signataire par défaut est l'employé du jeton ; comme pour une visite,
 * il peut être un employé d'une clinique sœur.
 */
class EnregistrerMouvementStupefiantRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'produit_id' => ['required', $this->existeDansLOrganisation('produits')->where('est_stupefiant', true)],
            'quantite' => [
                'required', 'numeric', 'decimal:0,2', 'between:-9999.99,9999.99',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ((float) $value === 0.0) {
                        $fail('Indiquez une quantité non nulle.');
                    }
                },
            ],
            'patient_id' => [
                Rule::requiredIf(fn (): bool => (float) $this->input('quantite') < 0),
                'nullable',
                $this->existeDansLOrganisation('patients'),
            ],
            'employe_id' => ['sometimes', 'nullable', $this->existeDansLesCliniquesDeLOrganisation('employes')],
            'motif' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'produit_id.exists' => 'Produit stupéfiant introuvable dans le catalogue.',
            'patient_id.required' => 'Indiquez le patient pour une sortie.',
        ];
    }
}
