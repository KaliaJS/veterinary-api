<?php

namespace App\Http\Requests\V1\Disponibilites;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

class DisponibilitesRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'ligne_planning_id' => ['sometimes', $this->existeDansLaClinique('lignes_planning')],
        ];
    }
}
