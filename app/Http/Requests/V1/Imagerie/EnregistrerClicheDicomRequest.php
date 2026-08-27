<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Flux DICOM entrant — la modalité pousse ses instances : identifiants
 * DICOM, chemin du fichier stocké, métadonnées utiles à la
 * visionneuse. Idempotent par SOP Instance UID.
 */
class EnregistrerClicheDicomRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sop_instance_uid' => ['required', 'string', 'max:64'],
            'study_instance_uid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'series_instance_uid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'nom_fichier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'chemin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadonnees' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
