<?php

namespace App\Http\Requests\V1\Transcription;

use Illuminate\Foundation\Http\FormRequest;

/** Fin de la consultation : le nombre de parties enregistrées, envoyées ou encore en route. */
class TerminerEnregistrementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parties' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
