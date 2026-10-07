<?php

namespace App\Http\Requests\V1\Site;

use Illuminate\Foundation\Http\FormRequest;

/** Photo d'employé : image matricielle (pas de SVG), 4 Mo au plus. */
class TeleverserPhotoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.mimes' => 'La photo doit être une image PNG, JPG ou WebP.',
            'photo.image' => 'La photo doit être une image PNG, JPG ou WebP.',
            'photo.max' => 'La photo ne doit pas dépasser 4 Mo.',
        ];
    }
}
