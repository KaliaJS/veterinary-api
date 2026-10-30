<?php

namespace App\Http\Requests\V1\Videos;

use Illuminate\Foundation\Http\FormRequest;

/** Les intervalles regardés depuis le dernier envoi, en secondes. */
class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ranges' => ['required', 'array', 'max:200'],
            'ranges.*' => ['required', 'array', 'size:2'],
            'ranges.*.*' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ranges.*' => 'Les secondes regardées sont illisibles.'];
    }
}
