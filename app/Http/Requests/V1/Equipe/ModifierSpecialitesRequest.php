<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\Specialite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Spécialités d'un vétérinaire : la liste entière (aucune possible). */
class ModifierSpecialitesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'specialites' => ['present', 'array'],
            'specialites.*' => ['distinct', Rule::enum(Specialite::class)],
        ];
    }
}
