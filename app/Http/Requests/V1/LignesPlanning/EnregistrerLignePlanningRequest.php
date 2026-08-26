<?php

namespace App\Http\Requests\V1\LignesPlanning;

use App\Enums\RoleLignePlanning;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Une ligne est soit un praticien (`employe_id`), soit une ressource
 * (`salle_id` : bloc, imagerie) — jamais les deux (docs/data-model.md).
 */
class EnregistrerLignePlanningRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employe_id' => ['required_without:salle_id', 'nullable', $this->existeDansLaClinique('employes')],
            'salle_id' => ['required_without:employe_id', 'nullable', $this->existeDansLaClinique('salles')],
            'nom' => ['required', 'string', 'max:255'],
            'sous_titre' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::enum(RoleLignePlanning::class)],
            'initiales' => ['nullable', 'string', 'max:3'],
            'groupe' => ['required', 'string', 'max:255'],
            'ordre' => ['sometimes', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('employe_id') && $this->filled('salle_id')) {
                $validator->errors()->add('salle_id', 'Une ligne porte un praticien ou une ressource, jamais les deux.');
            }
        });
    }
}
