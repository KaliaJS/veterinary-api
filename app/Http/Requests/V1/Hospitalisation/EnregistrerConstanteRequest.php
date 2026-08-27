<?php

namespace App\Http\Requests\V1\Hospitalisation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Relevé de constantes au chevet : heure locale du jour en minutes,
 * température en °C, fréquences cardiaque et respiratoire.
 */
class EnregistrerConstanteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mesure_a' => ['required', 'integer', 'between:0,1439'],
            'temperature' => ['required', 'numeric', 'decimal:0,1', 'between:25,45'],
            'fc' => ['required', 'integer', 'between:1,400'],
            'fr' => ['required', 'integer', 'between:1,200'],
        ];
    }
}
