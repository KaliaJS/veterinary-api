<?php

namespace App\Http\Requests\V1\Espace;

use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;

/** « Déjà connu d'une clinique ? » : réclamation d'un animal par numéro de puce. */
class ReclamerAnimalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'puce' => ['required', 'string', 'max:32', new NumeroPuce],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'puce.required' => 'Indiquez le numéro de puce de votre animal.',
        ];
    }
}
