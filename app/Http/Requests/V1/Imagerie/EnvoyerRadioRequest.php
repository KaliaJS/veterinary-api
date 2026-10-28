<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;

/** Une radio DICOM envoyée depuis l'examen (28.10.2026) : l'original, tel quel. */
class EnvoyerRadioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fichier' => ['required', 'file', 'max:'.config('imagerie.taille_max_ko')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fichier.required' => 'Choisissez un fichier DICOM.',
            'fichier.max' => 'Ce fichier dépasse 100 Mo.',
        ];
    }
}
