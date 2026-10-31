<?php

namespace App\Http\Requests\V1\Videos;

use Illuminate\Foundation\Http\FormRequest;

/** L'affiche d'une vidéo : image matricielle (pas de SVG), 8 Mo au plus ; sa taille minimale se juge une fois redressée (VideoPosters). */
class StoreVideoPosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // La taille minimale se juge sur la photo redressée, à l'enregistrement (VideoPosters::store)
            'poster' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'poster.required' => 'Choisissez une image.',
            'poster.mimes' => 'L’affiche doit être une image PNG, JPG ou WebP.',
            'poster.image' => 'L’affiche doit être une image PNG, JPG ou WebP.',
            'poster.max' => 'L’affiche ne doit pas dépasser 8 Mo.',
            'poster.dimensions' => 'L’affiche mesure au plus 8000 pixels de côté.',
        ];
    }
}
