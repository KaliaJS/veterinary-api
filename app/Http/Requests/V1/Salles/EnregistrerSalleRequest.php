<?php

namespace App\Http\Requests\V1\Salles;

use App\Enums\TypeSalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerSalleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(TypeSalle::class)],
        ];
    }
}
