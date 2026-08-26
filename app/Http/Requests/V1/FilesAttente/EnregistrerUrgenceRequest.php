<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Enums\Espece;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerUrgenceRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:255'],
            'triage' => ['required', 'integer', 'min:1', 'max:3'],
            'duree_minutes' => ['required', 'integer', 'min:10', 'max:240'],
        ];
    }
}
