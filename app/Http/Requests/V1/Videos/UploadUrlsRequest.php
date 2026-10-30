<?php

namespace App\Http\Requests\V1\Videos;

use App\Services\Videos\VideoStorage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Les fichiers à envoyer : des noms simples (listes HLS et segments), jamais un chemin. */
class UploadUrlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:200'],
            'files.*' => ['required', 'string', 'max:80', function (string $attribute, mixed $name, Closure $fail): void {
                if (! is_string($name) || ! VideoStorage::isValidName($name) || preg_match(VideoStorage::UPLOADABLE, $name) !== 1) {
                    $fail('Ce fichier ne fait pas partie d’une vidéo.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.required' => 'Aucun fichier à envoyer.',
            'files.max' => 'Pas plus de 200 fichiers à la fois.',
        ];
    }
}
