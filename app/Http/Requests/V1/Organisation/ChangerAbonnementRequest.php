<?php

namespace App\Http\Requests\V1\Organisation;

use App\Enums\FormuleSaas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangerAbonnementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::enum(FormuleSaas::class)],
            'cycle' => ['sometimes', Rule::in(['mois', 'an'])],
        ];
    }
}
