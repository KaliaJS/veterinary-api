<?php

namespace App\Http\Requests\V1\Bloc;

use Illuminate\Foundation\Http\FormRequest;

class CocherPointChecklistRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'est_coche' => ['required', 'boolean'],
        ];
    }
}
