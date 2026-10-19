<?php

namespace App\Http\Requests\V1\Transcription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Un morceau de l'audio d'une partie : son rang, le nombre total de
 * morceaux, le format du fichier entier (sans ses paramètres de codec).
 */
class MorceauAudioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['format' => Str::lower(trim(Str::before((string) $this->input('format'), ';')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'morceau' => ['required', 'integer', 'min:0'],
            'morceaux' => ['required', 'integer', 'min:1', 'max:'.config('transcription.morceaux_max')],
            'format' => ['required', 'string', Rule::in(config('transcription.formats'))],
            'fichier' => ['required', 'file', 'max:'.config('transcription.morceau_max_ko')],
        ];
    }
}
