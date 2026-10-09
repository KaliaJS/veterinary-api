<?php

namespace App\Http\Requests\V1\Facturation;

use Illuminate\Foundation\Http\FormRequest;

/** Appairage d'un terminal : le code affiché par le lecteur, et son nom à la caisse. */
class AppairerTerminalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code_appairage' => ['required', 'string', 'max:100'],
            'libelle' => ['required', 'string', 'max:60'],
        ];
    }
}
