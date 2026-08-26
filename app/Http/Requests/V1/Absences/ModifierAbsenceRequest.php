<?php

namespace App\Http\Requests\V1\Absences;

use App\Enums\TypeAbsence;
use App\Models\Absence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Période, type et note sont modifiables (docs/data-model.md §1) ; le
 * statut ne change que par les endpoints valider / refuser.
 */
class ModifierAbsenceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(TypeAbsence::class)],
            'debut_le' => ['sometimes', 'date'],
            'fin_le' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Absence $absence */
            $absence = $this->route('absence');

            $debut = $this->date('debut_le') ?? $absence->debut_le;
            $fin = $this->date('fin_le') ?? $absence->fin_le;

            if ($fin->lt($debut)) {
                $validator->errors()->add('fin_le', 'Le dernier jour ne peut pas précéder le premier.');
            }
        });
    }
}
