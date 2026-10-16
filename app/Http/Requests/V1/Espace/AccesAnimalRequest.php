<?php

namespace App\Http\Requests\V1\Espace;

use App\Enums\AccesClinique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Droit accordé à la clinique sur l'identité de l'animal. */
class AccesAnimalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acces_clinique' => ['required', Rule::enum(AccesClinique::class)],
        ];
    }
}
