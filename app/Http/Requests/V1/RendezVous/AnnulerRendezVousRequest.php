<?php

namespace App\Http\Requests\V1\RendezVous;

use Illuminate\Foundation\Http\FormRequest;

class AnnulerRendezVousRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motif' => ['nullable', 'string', 'max:255'],
        ];
    }
}
