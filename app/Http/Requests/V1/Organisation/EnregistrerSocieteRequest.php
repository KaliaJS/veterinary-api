<?php

namespace App\Http\Requests\V1\Organisation;

use App\Rules\NumeroIde;
use Illuminate\Foundation\Http\FormRequest;

class EnregistrerSocieteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'ide' => ['nullable', 'string', 'max:30', new NumeroIde],
        ];
    }
}
