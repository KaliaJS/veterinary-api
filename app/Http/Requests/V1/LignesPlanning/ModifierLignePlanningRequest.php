<?php

namespace App\Http\Requests\V1\LignesPlanning;

use App\Enums\RoleLignePlanning;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\LignePlanning;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ModifierLignePlanningRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employe_id' => ['sometimes', 'nullable', $this->employeDeLaClinique()],
            'salle_id' => ['sometimes', 'nullable', $this->existeDansLaClinique('salles')],
            'nom' => ['sometimes', 'string', 'max:255'],
            'sous_titre' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(RoleLignePlanning::class)],
            'initiales' => ['sometimes', 'nullable', 'string', 'max:3'],
            'groupe' => ['sometimes', 'string', 'max:255'],
            'ordre' => ['sometimes', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LignePlanning $ligne */
            $ligne = $this->route('lignePlanning');

            $employe = $this->has('employe_id') ? $this->input('employe_id') : $ligne->employe_id;
            $salle = $this->has('salle_id') ? $this->input('salle_id') : $ligne->salle_id;

            if ($employe !== null && $salle !== null) {
                $validator->errors()->add('salle_id', 'Une ligne porte un praticien ou une ressource, jamais les deux.');
            }

            if ($employe === null && $salle === null) {
                $validator->errors()->add('employe_id', 'Une ligne porte un praticien ou une ressource.');
            }
        });
    }
}
