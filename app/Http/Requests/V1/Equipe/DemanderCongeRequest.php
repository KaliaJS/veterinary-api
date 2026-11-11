<?php

namespace App\Http\Requests\V1\Equipe;

use Illuminate\Foundation\Http\FormRequest;

/** Une demande de congé de l'employé lui-même : dès aujourd'hui, deux ans d'avance au plus. */
class DemanderCongeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'debut_le' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.aujourdhui()->toDateString()],
            'fin_le' => ['required', 'date_format:Y-m-d', 'after_or_equal:debut_le', 'before_or_equal:'.aujourdhui()->addYears(2)->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Une date envoyée en liste : une erreur de validation, jamais du serveur */
    protected function prepareForValidation(): void
    {
        foreach (['debut_le', 'fin_le'] as $champ) {
            if ($this->has($champ) && ! is_string($this->input($champ))) {
                $this->merge([$champ => '']);
            }
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'debut_le.after_or_equal' => 'Un congé se demande pour aujourd’hui ou plus tard.',
            'fin_le.after_or_equal' => 'Le dernier jour vient après le premier.',
        ];
    }
}
