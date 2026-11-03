<?php

namespace App\Http\Requests\V1\Stocks;

use App\Support\AtcCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le code ATC d'un article existant (03.11.2026). `atc_code` est exigé :
 * null l'efface, explicitement ; un corps vide est refusé et ne touche à
 * rien.
 */
class UpdateAtcCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('atc_code')) {
            $this->merge(['atc_code' => AtcCode::normalize($this->input('atc_code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'atc_code' => ['present', 'nullable', 'string', 'max:12', 'regex:'.AtcCode::PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return AtcCode::messages();
    }
}
