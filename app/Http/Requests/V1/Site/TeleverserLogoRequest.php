<?php

namespace App\Http\Requests\V1\Site;

use Illuminate\Foundation\Http\FormRequest;

/** Logo : image matricielle (pas de SVG, qui peut porter du script), 2 Mo au plus. */
class TeleverserLogoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.mimes' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.image' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
        ];
    }
}
