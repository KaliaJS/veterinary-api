<?php

namespace App\Http\Requests\V1\Videos;

use Illuminate\Foundation\Http\FormRequest;

/** Un brouillon de vidéo : un titre provisoire, le reste vient avant la mise en vente. */
class StoreVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['title.max' => 'Le titre dépasse 160 caractères.'];
    }
}
