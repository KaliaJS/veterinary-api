<?php

namespace App\Http\Requests\V1\Organisation;

use Illuminate\Foundation\Http\FormRequest;

/** Photo d'une clinique : image matricielle (pas de SVG), 8 Mo au plus. */
class TeleverserPhotoCliniqueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => 'Choisissez une photo.',
            'photo.mimes' => 'La photo doit être une image PNG, JPG ou WebP.',
            'photo.image' => 'La photo doit être une image PNG, JPG ou WebP.',
            'photo.max' => 'La photo ne doit pas dépasser 8 Mo.',
        ];
    }
}
