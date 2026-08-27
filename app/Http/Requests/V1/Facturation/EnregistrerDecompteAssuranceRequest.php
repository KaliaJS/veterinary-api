<?php

namespace App\Http\Requests\V1\Facturation;

use Illuminate\Foundation\Http\FormRequest;

class EnregistrerDecompteAssuranceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assureur' => ['required', 'string', 'max:100'],
        ];
    }
}
