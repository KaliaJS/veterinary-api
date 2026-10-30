<?php

namespace App\Http\Requests\V1\Videos;

use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;

/** Avant d'envoyer : la durée (3 heures au plus) et la taille estimée, pour le quota. */
class StartUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'duration_seconds' => ['required', 'numeric', 'min:1', 'max:'.Video::MAX_DURATION_SECONDS],
            'estimated_bytes' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'duration_seconds.max' => 'Une vidéo dure 3 heures au plus.',
            'duration_seconds.*' => 'La durée de la vidéo est illisible.',
            'estimated_bytes.*' => 'La taille de la vidéo est illisible.',
        ];
    }
}
