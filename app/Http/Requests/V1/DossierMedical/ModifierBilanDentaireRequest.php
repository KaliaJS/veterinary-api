<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\CalculusGrade;
use App\Enums\GingivitisGrade;
use App\Enums\Occlusion;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le bilan de la bouche, seul (04.11.2026) : seuls les champs reçus
 * changent (null : non relevé).
 */
class ModifierBilanDentaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user()?->can('update', $patient) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gingivitis' => ['sometimes', 'nullable', Rule::enum(GingivitisGrade::class)],
            'calculus' => ['sometimes', 'nullable', Rule::enum(CalculusGrade::class)],
            'occlusion' => ['sometimes', 'nullable', Rule::enum(Occlusion::class)],
            'local_block' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'gingivitis' => 'Choisissez aucune, légère, modérée ou sévère.',
            'calculus' => 'Choisissez aucun, minimal, léger, modéré ou massif.',
            'occlusion' => 'Choisissez normale ou MAL1 à MAL4.',
            'local_block.max' => 'Le bloc local tient en 200 caractères.',
        ];
    }
}
