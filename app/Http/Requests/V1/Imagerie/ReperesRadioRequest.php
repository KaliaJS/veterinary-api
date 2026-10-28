<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Région et incidence d'une radio, validées par un humain dans les listes fixes. */
class ReperesRadioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'region' => ['required', 'string', Rule::in(array_keys(config('imagerie.regions')))],
            'incidence' => ['required', 'string', Rule::in(array_keys(config('imagerie.incidences')))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'region.in' => 'Choisissez une région de la liste.',
            'incidence.in' => 'Choisissez une incidence de la liste.',
        ];
    }
}
