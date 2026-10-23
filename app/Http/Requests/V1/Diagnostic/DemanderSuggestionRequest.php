<?php

namespace App\Http\Requests\V1\Diagnostic;

use Illuminate\Foundation\Http\FormRequest;

/** Le compte rendu tel qu'il est à l'écran, enregistré ou non : la suggestion part de lui. */
class DemanderSuggestionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            's' => ['present', 'nullable', 'string', 'max:10000'],
            'o' => ['present', 'nullable', 'string', 'max:10000'],
            'a' => ['present', 'nullable', 'string', 'max:10000'],
            'p' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array{s: string, o: string, a: string, p: string} */
    public function soap(): array
    {
        return array_map(fn ($texte) => trim((string) $texte), $this->safe()->only(['s', 'o', 'a', 'p']));
    }
}
