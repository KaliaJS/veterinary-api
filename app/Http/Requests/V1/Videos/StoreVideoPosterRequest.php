<?php

namespace App\Http\Requests\V1\Videos;

use App\Services\Videos\VideoPosters;
use Illuminate\Foundation\Http\FormRequest;

/** L'affiche d'une vidéo : image matricielle (pas de SVG), 8 Mo au plus, assez grande pour rester nette. */
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
            'poster' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192',
                'dimensions:min_width='.VideoPosters::MIN_WIDTH.',min_height='.VideoPosters::MIN_HEIGHT.',max_width=8000,max_height=8000'],
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
            'poster.dimensions' => 'L’affiche doit mesurer au moins '.VideoPosters::MIN_WIDTH.' × '.VideoPosters::MIN_HEIGHT.' pixels.',
        ];
    }
}
