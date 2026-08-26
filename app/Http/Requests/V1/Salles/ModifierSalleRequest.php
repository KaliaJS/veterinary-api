<?php

namespace App\Http\Requests\V1\Salles;

use App\Enums\TypeSalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierSalleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(TypeSalle::class)],
        ];
    }
}
