<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Enums\Espece;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerListeAttenteRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', $this->existeDansLOrganisation('patients')],
            'patient_name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', Rule::enum(Espece::class)],
            'owner_name' => ['required', 'string', 'max:255'],
            'tel' => ['nullable', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:255'],
            'preference' => ['nullable', 'string', 'max:255'],
            'ligne_planning_id' => ['nullable', $this->existeDansLaClinique('lignes_planning')],
            'duree_minutes' => ['required', 'integer', 'min:10', 'max:240'],
        ];
    }
}
